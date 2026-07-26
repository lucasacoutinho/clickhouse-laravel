<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * ClickHouse-specific fluent column modifiers.
 *
 * @api
 */
class ClickHouseColumnDefinition extends ColumnDefinition
{
    public function codec(string $codec): static
    {
        return $this->set(
            'codec',
            ClickHouseSql::safeExpression($codec, 'column codec'),
        );
    }

    public function ttl(Expression|string $expression): static
    {
        if (is_string($expression)) {
            ClickHouseSql::safeExpression($expression, 'column TTL');
        }

        return $this->set('ttl', $expression);
    }

    public function materialized(Expression|string $expression): static
    {
        if (is_string($expression)) {
            ClickHouseSql::safeExpression($expression, 'column MATERIALIZED expression');
        }

        return $this->set('materialized', $expression);
    }

    public function alias(Expression|string $expression): static
    {
        if (is_string($expression)) {
            ClickHouseSql::safeExpression($expression, 'column ALIAS expression');
        }

        return $this->set('alias', $expression);
    }

    public function ephemeral(bool $value = true): static
    {
        return $this->set('ephemeral', $value);
    }

    public function lowCardinality(bool $value = true): static
    {
        return $this->set('low_cardinality', $value);
    }
}
