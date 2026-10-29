<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Ddl\Parser;

use IfCastle\AQL\Dsl\Ddl\AlterOption as AlterOptionNode;
use IfCastle\AQL\Dsl\Ddl\AlterOptionInterface;
use IfCastle\AQL\Dsl\Ddl\ChangeColumn;
use IfCastle\AQL\Dsl\Ddl\ColumnDefinitionInterface;
use IfCastle\AQL\Dsl\Ddl\IndexDefinitionInterface;
use IfCastle\AQL\Dsl\Ddl\NameDefinition;
use IfCastle\AQL\Dsl\Parser\Exceptions\ParseException;
use IfCastle\AQL\Dsl\Parser\TokensIteratorInterface;
use IfCastle\AQL\Dsl\Sql\RawSql;

class AlterOption extends DdlParserAbstract
{
    /**
     * @throws ParseException
     */
    #[\Override]
    public function parseTokens(TokensIteratorInterface $tokens): AlterOptionInterface
    {
        $tableOptions               = (new TableOption())->parseOptions($tokens);

        [$type, $action, $line]     = $tokens->currentToken();

        if ($type !== T_STRING) {
            throw new ParseException('Expected ALTER action ' . \sprintf('(got \'%s\')', $action), ['line' => $line]);
        }

        [$type, $what, $line]       = $tokens->nextToken();
        $definition                 = null;

        // RENAME [TO | AS] new_tbl_name: without TO or AS the new name follows the action itself.
        if (\strtoupper((string) $action) === 'RENAME'
            && !\in_array(\strtoupper((string) $what), ['TO', 'AS', 'COLUMN', 'INDEX', 'KEY'], true)) {
            $definition             = new NameDefinition(ColumnDefinition::parseColumnName($tokens));
            $what                   = 'TABLE';
        } else {

            if ($type !== T_STRING) {
                throw new ParseException(
                    'Expected COLUMN, INDEX, KEY, CONSTRAINT, PARTITION ' . \sprintf('(got \'%s\')', $what), ['line' => $line]
                );
            }

            $tokens->nextTokens();
        }

        $what                       = \strtoupper((string) $what);
        $action                     = \strtoupper((string) $action);

        $isFulltext                 = false;
        $isSpatial                  = false;
        $isPrimaryKey               = false;
        $isForeignKey               = false;

        switch ($what) {

            case 'CONSTRAINT':
            case 'PARTITION':
            case 'COLUMN':
                break;
            case 'INDEX':
            case 'KEY':
                $what               = 'INDEX';
                break;

            case 'FULLTEXT':

                $isFulltext         = true;

                // no break
            case 'SPATIAL':

                if (!\in_array($tokens->nextTokens()->currentTokenAsString(), ['INDEX', 'KEY'])) {
                    throw new ParseException(
                        'Expected INDEX KEY keyword (got \'' . $tokens->currentTokenAsString() . '\')'
                    );
                }

                if (!$isFulltext) {
                    $isSpatial      = true;
                }

                $what               = 'INDEX';
                break;

            case 'PRIMARY':
                $isPrimaryKey       = true;
                // no break
            case 'FOREIGN':

                if (!$isPrimaryKey) {
                    $isForeignKey   = true;
                }

                if ($tokens->nextTokens()->currentTokenAsString() !== 'KEY') {
                    throw new ParseException('Expected KEY keyword ' . \sprintf('(got \'%s\')', $tokens->currentTokenAsString()));
                }

                $what               = 'INDEX';
                break;

                // Options for RENAME table
            case 'TABLE':
            case 'TO':
            case 'AS':
                $what               = 'TABLE';
                break;

            default:
                throw new ParseException(\sprintf('Not supported ALTER TABLE expression \'%s %s\'', $action, $what), ['line' => $line]);
        }

        if (!\in_array($what, ['COLUMN', 'INDEX', 'TABLE'])) {
            throw new ParseException([
                'template'          => 'Unsupported operation: {action} {what}',
                'action'            => $action,
                'what'              => $what,
            ]);
        }

        switch ($action) {

            case 'ADD':

                if ($what === 'COLUMN') {
                    if ($tokens->currentTokenAsString() === '(') {
                        // case: ADD [COLUMN] (col_name column_definition,...)
                        $definition = $this->columnDefinitionWithComma($tokens);
                    } else {
                        $definition = $this->columnDefinition($tokens);
                    }

                } elseif ($what === 'INDEX') {
                    $definition     = $this->indexDefinition($tokens);

                    if ($isFulltext) {
                        $definition->setIndexRole('FULLTEXT');
                    } elseif ($isPrimaryKey) {
                        $definition->setIndexRole('PRIMARY');
                    } elseif ($isSpatial) {
                        $definition->setIndexRole('SPATIAL');
                    } elseif ($isForeignKey) {
                        $definition->setIndexRole('FOREIGN');
                    }
                }

                break;

            case 'DROP':

                if ($isPrimaryKey) {
                    $definition     = new RawSql('PRIMARY KEY');
                } elseif ($isForeignKey) {
                    $definition     = new RawSql('FOREIGN KEY ' . static::columnOrIndexName($tokens));
                } else {
                    $definition     = new NameDefinition(static::columnOrIndexName($tokens));
                }

                break;

            case 'RENAME':

                switch ($what) {
                    case 'COLUMN':
                    case 'INDEX':
                        $definition         = (new Rename())->parseTokens($tokens);
                        break;
                    case 'TABLE':
                        $definition         ??= new NameDefinition(ColumnDefinition::parseColumnName($tokens));

                        break;
                    default:
                        throw new ParseException([
                            'template'          => 'Unsupported operation: {action} {what}',
                            'action'            => $action,
                            'what'              => $what,
                        ]);
                }

                break;

            case 'CHANGE':

                // CHANGE [COLUMN] old_col_name new_col_name column_definition
                //        [FIRST | AFTER col_name]
                $definition         = new ChangeColumn(
                    ColumnDefinition::parseColumnName($tokens),
                    static::columnDefinition($tokens)
                );
                break;

            case 'MODIFY':

                $definition         = $this->columnDefinition($tokens);
                break;

            case 'ALTER':
            case 'DEFAULT':
            case 'CHARACTER':
            case 'DISABLE':
            case 'ENABLE':
            case 'DISCARD':
            case 'IMPORT':
            case 'FORCE':
            case 'LOCK':
            default:
                throw new ParseException([
                    'template'          => 'Unsupported operation: {action} {what}',
                    'action'            => $action,
                    'what'              => $what,
                ]);
        }

        return (new AlterOptionNode($what, $action, $definition))->setTableOptions($tableOptions);
    }

    protected function columnDefinition(TokensIteratorInterface $tokens): ColumnDefinitionInterface
    {
        static $firstOrAfter        = ['FIRST', 'AFTER'];

        $stopTokens                 = $tokens->getStopTokens();

        $column                     = (new ColumnDefinition())->parseTokens($tokens->addStopTokens($firstOrAfter));

        $tokens->setStopTokens($stopTokens);

        $afterColumn                = null;

        switch ($tokens->currentTokenAsString()) {
            case 'FIRST':
                $afterColumn        = 0;
                break;
            case 'AFTER':
                $afterColumn        = ColumnDefinition::parseColumnName($tokens->nextTokens());
                break;
        }

        if ($afterColumn !== null) {
            $column->setAfterColumn($afterColumn);
        }

        return $column;
    }

    protected function columnDefinitionWithComma(TokensIteratorInterface $tokens): array
    {
        $columns                    = [];

        $isBracket                  = $tokens->currentTokenAsString() === '(';

        if ($isBracket) {
            $tokens->nextTokens();
        }

        while ($tokens->valid()) {

            $columns[]              = $this->columnDefinition($tokens);

            if ($tokens->currentTokenAsString() === ')') {
                break;
            }

            if ($tokens->currentTokenAsString() !== ',') {
                throw new ParseException('Expected delimiter "," ' . \sprintf('(got \'%s\')', $tokens->currentTokenAsString()));
            }

            $tokens->nextTokens();
        }

        if ($isBracket && $tokens->currentTokenAsString() !== ')') {
            throw new ParseException(
                'Column definition expected closing bracket ")" (got \'' . $tokens->currentTokenAsString() . '\')'
            );
        }

        if ($isBracket) {
            $tokens->nextTokens();
        }

        return $columns;
    }

    protected function indexDefinition(TokensIteratorInterface $tokens): IndexDefinitionInterface
    {
        return (new IndexDefinition())->parseTokens($tokens);
    }

    protected function columnOrIndexName(TokensIteratorInterface $tokens): string
    {
        return ColumnDefinition::parseColumnName($tokens);
    }
}
