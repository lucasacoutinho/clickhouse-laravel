<?php

namespace ClickHouse\Laravel\Exceptions;

final class ClickHouseGrammarException extends \RuntimeException
{
    public static function deleteWithoutWhere(): self
    {
        return new self('ClickHouse DELETE requires a WHERE clause. Use TRUNCATE to remove all rows.');
    }

    public static function updateWithoutWhere(): self
    {
        return new self(
            'ClickHouse UPDATE requires a WHERE clause. Use whereRaw(\'1\') for an intentional full-table mutation.'
        );
    }

    public static function missingTableForInsert(): self
    {
        return new self('No table specified for INSERT statement.');
    }

    public static function ambiguousJoinKeys(): self
    {
        return new self('A ClickHouse JOIN cannot use both ON and USING simultaneously.');
    }

    public static function missingJoinKeys(): self
    {
        return new self('A ClickHouse JOIN requires either USING columns or ON conditions.');
    }

    public static function missingSubqueryAlias(): self
    {
        return new self('A ClickHouse subquery JOIN requires an alias.');
    }

    public static function preWhereOnMutation(): self
    {
        return new self('ClickHouse PREWHERE is only supported on SELECT queries, not mutations.');
    }

    public static function unsupportedMutationClause(string $clause): self
    {
        return new self("ClickHouse UPDATE/DELETE mutations do not support {$clause}.");
    }

    public static function onClusterInsert(): self
    {
        return new self(
            'ClickHouse INSERT does not support ON CLUSTER. Insert into a Distributed or replicated table instead.'
        );
    }
}
