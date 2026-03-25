<?php

namespace ClickHouse\Laravel\Exceptions;

class ClickHouseGrammarException extends \RuntimeException
{
    public static function deleteWithoutWhere(): static
    {
        return new static('ClickHouse DELETE requires a WHERE clause. Use TRUNCATE to remove all rows.');
    }

    public static function missingTableForInsert(): static
    {
        return new static('No table specified for INSERT statement.');
    }

    public static function ambiguousJoinKeys(): static
    {
        return new static('A ClickHouse JOIN cannot use both ON and USING simultaneously.');
    }

    public static function missingJoinKeys(): static
    {
        return new static('A ClickHouse JOIN requires either USING columns or ON conditions.');
    }
}
