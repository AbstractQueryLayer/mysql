<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Executor;

use IfCastle\AQL\Dsl\Node\NodeInterface;
use IfCastle\AQL\Dsl\Sql\Column\ColumnInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\JoinInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\SubjectInterface;
use IfCastle\AQL\Dsl\Sql\Query\Expression\Using;
use IfCastle\AQL\Dsl\Sql\Query\Expression\WhereEntity;
use IfCastle\AQL\Dsl\Sql\Query\QueryInterface;
use IfCastle\AQL\Dsl\Sql\Query\SubqueryInterface;
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

        if (!$query->isDelete() || $subject !== $query->getFrom()?->getSubject()) {
            return;
        }

        if ($this->isSingleTableDelete($query, $context)) {
            $subject->setSubjectAlias('');
            return;
        }

        // A multi-table DELETE removes rows only from the tables named before FROM: by default, the main one.
        if (($query->getUsing()?->getAql() ?? '') === '') {
            $query->setUsing(new Using($query->getMainEntityName()));
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

        if ($query === null || !$this->isSingleTableDelete($query, $deleteContext)) {
            return;
        }

        $mainAlias                  = $deleteContext->findAlias($query->getMainEntityName());

        if ($mainAlias !== null && $column->getSubjectAlias() === $mainAlias) {
            $column->setSubjectAlias($column->getSubject() ?? '');
        }
    }

    /**
     * A DELETE with neither a USING list nor a JOIN: the form MariaDB accepts without an alias. A column of another
     * entity of this storage, outside a subquery, will add a join: the DELETE is decided on before that join exists.
     */
    private function isSingleTableDelete(QueryInterface $query, NodeContextInterface $context): bool
    {
        return $query->isDelete()
            && ($query->getUsing()?->getAql() ?? '') === ''
            && !$this->hasJoinInStatement($query, $context)
            && !$this->joinsInStatement($query, $query->getMainEntityName(), $context);
    }

    /**
     * A join to an entity of another storage stays in FROM, but the plan reads that entity with a query of its own.
     */
    private function hasJoinInStatement(QueryInterface $query, NodeContextInterface $context): bool
    {
        foreach ($query->getFrom()?->getChildJoins() ?? [] as $join) {
            $entity                 = $join instanceof JoinInterface ? $context->findEntity($join->getSubject()->getSubjectName()) : null;

            if ($entity === null || $this->storageOf($entity) === $this->storageOf($context->getEntity($query->getMainEntityName()))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a column under $node names an entity other than the main one that the same statement must join: an
     * entity of another storage is read by a query of its own, and a subquery or an ENTITY condition joins nothing.
     */
    private function joinsInStatement(NodeInterface $node, string $mainEntityName, NodeContextInterface $context): bool
    {
        foreach ($node->getChildNodes() as $child) {
            if ($child === null || $child instanceof SubqueryInterface || $child instanceof WhereEntity) {
                continue;
            }

            if ($child instanceof ColumnInterface && $this->isJoinedEntity($child->getEntityName(), $mainEntityName, $context)) {
                return true;
            }

            if ($this->joinsInStatement($child, $mainEntityName, $context)) {
                return true;
            }
        }

        return false;
    }

    private function isJoinedEntity(?string $entityName, string $mainEntityName, NodeContextInterface $context): bool
    {
        if ($entityName === null || $entityName === '' || $entityName === $mainEntityName) {
            return false;
        }

        $entity                     = $context->findEntity($entityName);

        return $entity !== null
            && $entity->getEntityName() !== $mainEntityName
            && $this->storageOf($entity) === $this->storageOf($context->getEntity($mainEntityName));
    }
}
