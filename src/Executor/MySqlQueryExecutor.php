<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Executor;

use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\SubjectInterface;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Entity\EntityInterface;
use IfCastle\AQL\Executor\Context\NodeContextInterface;
use IfCastle\AQL\Executor\SqlQueryExecutor;

/**
 * The SQL executor for MySQL and MariaDB.
 *
 * A single-table DELETE is rendered without a table alias: MariaDB before 11.6 has no alias there and answers with a
 * syntax error, and only the single-table form takes ORDER BY and LIMIT. Its columns are qualified with the table
 * name instead. A multi-table DELETE (`DELETE t0 FROM tbl AS t0 JOIN …`) keeps its aliases.
 */
class MySqlQueryExecutor extends SqlQueryExecutor
{
    #[\Override]
    public function handleSubject(SubjectInterface $subject, NodeContextInterface $context): void
    {
        parent::handleSubject($subject, $context);

        $subject                    = $subject->resolveNode();

        if (!$subject instanceof SubjectInterface) {
            return;
        }

        $query                      = $context->getCurrentSqlQuery();

        if (self::isSingleTableDelete($query) && $subject === $query->getFrom()?->getSubject()) {
            $subject->setSubjectAlias('');
        }
    }

    #[\Override]
    protected function handleProperty(ColumnInterface $column, NodeContextInterface $context, EntityInterface $entity): void
    {
        parent::handleProperty($column, $context, $entity);

        $column                     = $column->resolveNode();

        if (!$column instanceof ColumnInterface) {
            return;
        }

        // A column of a subquery that refers to the outer DELETE names the outer context's table.
        $deleteContext              = $column->isForeign() ? $context->findForeignContext() : $context;
        $query                      = $deleteContext?->getCurrentSqlQuery();

        if ($query === null || !self::isSingleTableDelete($query)) {
            return;
        }

        $mainAlias                  = $deleteContext->findAlias($query->getMainEntityName());

        if ($mainAlias !== null && $column->getSubjectAlias() === $mainAlias) {
            $column->setSubjectAlias($column->getSubject() ?? '');
        }
    }

    /**
     * A DELETE with neither a USING list nor a JOIN: the form MariaDB accepts without an alias.
     */
    private static function isSingleTableDelete(QueryInterface $query): bool
    {
        return $query->isDelete()
            && ($query->getUsing()?->getAql() ?? '') === ''
            && $query->getFrom()?->hasChildJoins() === false;
    }
}
