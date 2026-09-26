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
use IfCastle\AQL\Storage\Exceptions\ConnectFailed;
use IfCastle\AQL\Storage\Exceptions\DuplicateKeysException;
use IfCastle\AQL\Storage\Exceptions\QueryException;
use IfCastle\AQL\Storage\Exceptions\RecoverableException;
use IfCastle\AQL\Storage\Exceptions\ServerHasGoneAwayException;
use IfCastle\AQL\Storage\Exceptions\StorageException;
use IfCastle\DI\Exceptions\ConfigException;

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

        return match ($exception->errorInfo[0]) {
            // please see: https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
            1213                => new RecoverableException($exception->errorInfo[2], $sql, $exception),
            2006                => new ServerHasGoneAwayException($exception->errorInfo[2], $sql, $exception),
            1022                => new DuplicateKeysException($exception->errorInfo[2], $sql, $exception),
            default             => new QueryException($exception->errorInfo[2], $sql, $exception)
        };
    }

    #[\Override]
    protected function isNestedTransactionsSupported(): bool
    {
        return false;
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
