<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Storage;

use IfCastle\AQL\Dsl\Sql\FunctionReference\FunctionReferenceInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\FunctionHandlerInterface;
use IfCastle\AQL\Generator\Ddl\EntityToTableInterface;
use IfCastle\AQL\MySql\Ddl\Generator\EntityToTable;
use IfCastle\AQL\PdoDriver\PDOAbstract;
use IfCastle\AQL\SqlDriver\OpenTransactions;
use IfCastle\AQL\Storage\Exceptions\ConnectFailed;
use IfCastle\AQL\Storage\Exceptions\DuplicateKeysException;
use IfCastle\AQL\Storage\Exceptions\QueryException;
use IfCastle\AQL\Storage\Exceptions\RecoverableException;
use IfCastle\AQL\Storage\Exceptions\ServerHasGoneAwayException;
use IfCastle\AQL\Storage\Exceptions\StorageException;
use IfCastle\AQL\Transaction\IsolationLevelEnum;
use IfCastle\AQL\Transaction\TransactionInterface;
use IfCastle\DI\Exceptions\ConfigException;

use function Async\coroutine_context;
use function Async\current_coroutine;

/**
 * MySQL and MariaDB storage on pdo_mysql.
 *
 * One MySql instance serves every coroutine: its PDO runs the TrueAsync connection pool, which gives
 * each coroutine a connection of its own and keeps it while a transaction or a statement is open.
 * Build it at application startup, before coroutines share it: the constructor opens the pool.
 * Options PDO::ATTR_POOL_MAX, ATTR_POOL_MIN and ATTR_POOL_HEALTHCHECK_INTERVAL tune the pool.
 * The initial_queries config runs on every pooled connection as its init command.
 */
class MySql extends PDOAbstract implements FunctionHandlerInterface
{
    // Error codes of the server and of the client library, the same in MySQL and MariaDB:
    // https://mariadb.com/kb/en/mariadb-error-code-reference/
    private const int ER_DUP_KEY    = 1022;

    private const int ER_DUP_ENTRY  = 1062;

    // InnoDB rolls back the whole transaction of the deadlock victim, not only its statement.
    private const int ER_LOCK_DEADLOCK = 1213;

    private const int ER_DUP_ENTRY_WITH_KEY_NAME = 1586;

    private const int CR_SERVER_GONE_ERROR = 2006;

    private const int CR_SERVER_LOST = 2013;

    // Set by disconnect(): a pool opened later, by the first query, would be opened by several coroutines at once.
    private bool $disconnected      = false;

    /**
     * Opens the connection pool. With no driver option and no ATTR_POOL_MIN that talks to no server;
     * otherwise the PDO constructor connects, and the calling coroutine waits for the server.
     *
     * @throws ConfigException
     * @throws ConnectFailed when the server refuses the connection the constructor opens
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->connect();
    }

    /**
     * @throws ConnectFailed after disconnect(): the pool opens once, when the storage is built
     */
    #[\Override]
    public function connect(): void
    {
        if ($this->disconnected) {
            throw new ConnectFailed('MySql storage is disconnected and does not open its pool again');
        }

        parent::connect();
    }

    #[\Override]
    public function disconnect(): void
    {
        $this->disconnected         = true;
        parent::disconnect();
    }

    /**
     * @throws ConfigException when an option is named by a string, when the pool is switched off or
     *                         persistent connections are asked for, when initial_queries holds more
     *                         than one statement, or when both it and the init command option are set
     */
    #[\Override]
    protected function defineOptions(array $options): array
    {
        foreach (\array_keys($options) as $option) {
            // PDO skips string keys, so "PDO::ATTR_POOL_MAX" from a config file would change nothing.
            if (\is_string($option)) {
                throw new ConfigException("MySql option '$option' must be given by its integer value");
            }
        }

        $options                    = parent::defineOptions($options);

        // Without the pool, coroutines share one connection and interleave their queries on its socket.
        if (\array_key_exists(\PDO::ATTR_POOL_ENABLED, $options) && empty($options[\PDO::ATTR_POOL_ENABLED])) {
            throw new ConfigException('MySql storage runs a connection pool: PDO::ATTR_POOL_ENABLED cannot be off');
        }

        // A query run once after connect reaches one pooled connection; the init command reaches
        // each, and the pool repeats it after it resets a connection.
        if ($this->initialQueries !== '') {

            // mysqlnd checks only the first statement of an init command: a later one fails unseen.
            if (\str_contains(\rtrim($this->initialQueries, "; \t\n\r"), ';')) {
                throw new ConfigException('initial_queries must be one statement, e.g. SET NAMES utf8mb4, time_zone = \'+00:00\'');
            }

            if (\array_key_exists(\Pdo\Mysql::ATTR_INIT_COMMAND, $options)) {
                throw new ConfigException('Set either initial_queries or Pdo\\Mysql::ATTR_INIT_COMMAND, not both');
            }

            $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = $this->initialQueries;
            $this->initialQueries   = '';
        }

        return [\PDO::ATTR_POOL_ENABLED => true] + $options;
    }

    #[\Override]
    protected function isPooled(): bool
    {
        return true;
    }

    /**
     * Each coroutine has a pooled connection of its own, so its open transactions live in the context of
     * the coroutine: a coroutine it spawns starts with none, and so does one that got a finished one's id.
     */
    #[\Override]
    protected function openTransactions(): OpenTransactions
    {
        $context                    = coroutine_context();
        $open                       = $context->findLocal($this);

        if ($open === null) {
            $owner                  = \WeakReference::create(current_coroutine());
            $open                   = new OpenTransactions(static fn(): bool => $owner->get()?->isCompleted() ?? true);
            $context->set($this, $open);
        }

        return $open;
    }

    /**
     * A deadlock victim loses its whole transaction, and a lost connection loses whatever it had open.
     */
    #[\Override]
    protected function endsTransaction(StorageException $exception): bool
    {
        return $exception instanceof RecoverableException;
    }

    #[\Override]
    public function escape(string $value): string
    {
        return '`' . $value . '`';
    }

    #[\Override]
    protected function normalizeException(\Throwable $exception, string $sql): StorageException
    {
        if (false === $exception instanceof \PDOException) {
            return new QueryException($exception->getMessage(), $sql, $exception);
        }

        // errorInfo[0] is the SQLSTATE, shared by many errors; errorInfo[1] is the server or client code.
        $message                    = $exception->errorInfo[2] ?? $exception->getMessage();

        return match ($exception->errorInfo[1] ?? null) {
            self::ER_LOCK_DEADLOCK  => new RecoverableException($message, $sql, $exception),
            self::CR_SERVER_GONE_ERROR,
            self::CR_SERVER_LOST    => new ServerHasGoneAwayException($message, $sql, $exception),
            self::ER_DUP_KEY,
            self::ER_DUP_ENTRY,
            self::ER_DUP_ENTRY_WITH_KEY_NAME
                                    => new DuplicateKeysException($message, $sql, $exception),
            default                 => new QueryException($message, $sql, $exception)
        };
    }

    /**
     * @throws StorageException
     */
    #[\Override]
    protected function realBeginTransaction(TransactionInterface $transaction): void
    {
        $isolationLevel             = match ($transaction->getIsolationLevel()) {
            null                    => null,
            IsolationLevelEnum::UNCOMMITTED  => 'READ UNCOMMITTED',
            IsolationLevelEnum::COMMITTED    => 'READ COMMITTED',
            IsolationLevelEnum::REPEATABLE   => 'REPEATABLE READ',
            IsolationLevelEnum::SERIALIZABLE => 'SERIALIZABLE',
        };

        if ($isolationLevel === null) {
            $this->transactionCall($this->dbh->beginTransaction(...), 'BEGIN');
            return;
        }

        // SET TRANSACTION without SESSION reaches the next transaction only, so the level does not stay on
        // the pooled connection. Its open statement keeps the coroutine on that connection until BEGIN.
        $sql                        = 'SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel;
        $statement                  = $this->realExecuteQuery($sql);
        $this->transactionCall($this->dbh->beginTransaction(...), 'BEGIN');
        unset($statement);
    }

    #[\Override]
    public function newEntityToTableGenerator(EntityInterface $entity): EntityToTableInterface
    {
        return new EntityToTable($entity);
    }

    #[\Override]
    public function handleFunction(FunctionReferenceInterface $function, NodeContextInterface $context): void
    {
        // All supported functions are pure by MySQL 8.0.23
        switch ($function->getFunctionName()) {
            case 'DATE_ADD':
            case 'DATE_SUB':
            case 'NOW':
            case 'COUNT':
            case 'SUM':
            case 'MIN':
            case 'MAX':
            case 'AVG':
            case 'CONCAT':
            case 'CONCAT_WS':
            case 'SUBSTRING':
                $function->resolveSelf();
                break;
        }
    }
}
