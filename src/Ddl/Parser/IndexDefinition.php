<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Ddl\Parser;

use IfCastle\AQL\Dsl\Ddl\IndexDefinition as IndexDefinitionNode;
use IfCastle\AQL\Dsl\Ddl\IndexDefinitionInterface;
use IfCastle\AQL\Dsl\Parser\Exceptions\ParseException;
use IfCastle\AQL\Dsl\Parser\TokensIteratorInterface;

class IndexDefinition extends DdlParserAbstract
{
    public static function isIndexStart(TokensIteratorInterface $tokens): bool
    {
        return match ($tokens->currentTokenAsString()) {
            'INDEX', 'KEY', 'PRIMARY', 'UNIQUE', 'FULLTEXT', 'SPATIAL' => true,
            default => false,
        };
    }

    /**
     * @throws ParseException
     */
    #[\Override]
    public function parseTokens(TokensIteratorInterface $tokens): IndexDefinitionInterface
    {
        $token                      = $tokens->currentTokenAsString();
        $indexRole                  = null;

        if (\in_array($token, ['PRIMARY', 'UNIQUE', 'FULLTEXT', 'SPATIAL'], true)) {
            $indexRole              = $token;
            $tokens->nextTokens();
            $token                  = $tokens->currentTokenAsString();
        }

        $isKeyword                  = $token === 'INDEX' || $token === 'KEY';

        // INDEX or KEY is optional after UNIQUE, FULLTEXT and SPATIAL, and required otherwise: PRIMARY KEY, KEY, INDEX.
        if (!$isKeyword && ($indexRole === null || $indexRole === 'PRIMARY')) {
            throw new ParseException('Expected INDEX or KEY ' . \sprintf('(got \'%s\')', $token));
        }

        if ($isKeyword) {
            $tokens->nextTokens();
        }

        $indexName                  = ColumnDefinition::parseColumnName($tokens, false);
        $indexType                  = ColumnDefinition::parseColumnName($tokens, false);

        // parse expression (col_name, ...)
        $indexParts                 = ColumnDefinition::parseColumnsList($tokens);

        // skip unsupported options
        while ($tokens->valid() && !\in_array($tokens->currentTokenAsString(), [',', ')'])) {
            $tokens->nextTokens();
        }

        return new IndexDefinitionNode($indexName, $indexType, $indexRole, $indexParts);
    }
}
