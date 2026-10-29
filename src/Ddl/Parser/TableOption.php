<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Ddl\Parser;

use IfCastle\AQL\Dsl\Ddl\TableOption as TableOptionNode;
use IfCastle\AQL\Dsl\Ddl\TableOptionInterface;
use IfCastle\AQL\Dsl\Parser\Exceptions\ParseException;
use IfCastle\AQL\Dsl\Parser\TokensIteratorInterface;
use IfCastle\AQL\Dsl\Sql\RawSql;

class TableOption extends DdlParserAbstract
{
    public static function isTableOption(TokensIteratorInterface $tokens): bool
    {
        return match ($tokens->currentTokenAsString()) {
            'AUTOEXTEND_SIZE', 'AUTO_INCREMENT', 'AVG_ROW_LENGTH', 'DEFAULT', 'CHARACTER', 'CHARSET', 'COMMENT',
            'COMPRESSION', 'CONNECTION', 'DATA', 'INDEX', 'DIRECTORY', 'DELAY_KEY_WRITE', 'ENCRYPTION',
            'ENGINE', 'ENGINE_ATTRIBUTE', 'INSERT_METHOD', 'KEY_BLOCK_SIZE',
            'MAX_ROWS', 'MIN_ROWS', 'PACK_KEYS', 'PASSWORD', 'ROW_FORMAT',
            'SECONDARY_ENGINE_ATTRIBUTE', 'STATS_AUTO_RECALC', 'STATS_PERSISTENT',
            'STATS_SAMPLE_PAGES', 'TABLESPACE',
            'UNION' => true,
            default => false,
        };
    }

    /**
     * @throws ParseException
     */
    public function parseOptions(TokensIteratorInterface $tokens): array
    {
        $options                    = [];

        while ($tokens->valid()) {

            if (!static::isTableOption($tokens)) {
                break;
            }

            $options[]              = $this->parseTokens($tokens);

            if ($tokens->currentTokenAsString() !== ',') {
                break;
            }

            $tokens->nextTokens();
        }

        return $options;
    }

    #[\Override]
    public function parseTokens(TokensIteratorInterface $tokens): TableOptionInterface
    {
        // see table_option: https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
        $option                     = $tokens->currentTokenAsString();

        // DEFAULT CHARACTER SET, DEFAULT CHARSET, DATA DIRECTORY, INDEX DIRECTORY: the option's own keyword follows.
        if ($option === 'DEFAULT' || $option === 'DATA' || $option === 'INDEX') {
            $option                 .= ' ' . $tokens->nextTokens()->currentTokenAsString();
        }

        $name                       = $tokens->currentTokenAsString();
        $tokens->nextTokens();

        switch ($name) {
            case 'CHARACTER':

                if ($tokens->currentTokenAsString() !== 'SET') {
                    throw new ParseException(
                        'Expected CHARACTER SET ' . \sprintf('(got \'%s\')', $tokens->currentTokenAsString())
                    );
                }

                $option             .= ' SET';
                $value              = $this->parseEqualValue($tokens->nextTokens());
                break;

            case 'TABLESPACE':

                // TABLESPACE tablespace_name [STORAGE {DISK | MEMORY}]
                $value              = $this->parseEqualValue($tokens);

                if ($tokens->currentTokenAsString() === 'STORAGE') {

                    $tokens->nextTokens();

                    if (\in_array($tokens->currentTokenAsString(), ['DISK', 'MEMORY'])) {
                        $tokens->nextTokens();
                    }
                }

                break;

            case 'UNION':

                // UNION [=] (tbl_name[,tbl_name]...)
                if ($tokens->currentTokenAsString() === '=') {
                    $tokens->nextTokens();
                }

                $value              = (new ColumnDefinition())->parseColumnsList($tokens);
                break;

            case 'AUTOEXTEND_SIZE':
            case 'AUTO_INCREMENT':
            case 'CHARSET':
            case 'AVG_ROW_LENGTH':
            case 'COMMENT':
            case 'COMPRESSION':
            case 'CONNECTION':
            case 'DIRECTORY':
            case 'DELAY_KEY_WRITE':
            case 'ENCRYPTION':
            case 'ENGINE':
            case 'ENGINE_ATTRIBUTE':
            case 'INSERT_METHOD':
            case 'KEY_BLOCK_SIZE':
            case 'MAX_ROWS':
            case 'MIN_ROWS':
            case 'PACK_KEYS':
            case 'PASSWORD':
            case 'ROW_FORMAT':
            case 'SECONDARY_ENGINE_ATTRIBUTE':
            case 'STATS_AUTO_RECALC':
            case 'STATS_PERSISTENT':
            case 'STATS_SAMPLE_PAGES':

                $value              = $this->parseEqualValue($tokens);
                break;

            default:
                throw new ParseException('Unknown table option: ' . $name);
        }

        return new TableOptionNode($option, $value);
    }

    protected function parseEqualValue(TokensIteratorInterface $tokens): string|int|float|RawSql
    {
        if ($tokens->currentTokenAsString() === '=') {
            $tokens->nextTokens();
        }

        [$type, $token, $line]      = $tokens->currentToken();

        if (\in_array($type, [T_LNUMBER, T_DNUMBER])) {
            $value                  = $type === T_LNUMBER ? (int) $token : (float) $token;
        } elseif ($type == T_STRING) {
            $value                  = new RawSql($token);
        } elseif ($type === T_CONSTANT_ENCAPSED_STRING) {
            $value                  = \substr((string) $token, 1, -1);
        } else {
            throw new ParseException(
                'Expected value ' . \sprintf('(got \'%s\')', $token), ['line' => $line]
            );
        }

        $tokens->nextTokens();

        return $value;
    }
}
