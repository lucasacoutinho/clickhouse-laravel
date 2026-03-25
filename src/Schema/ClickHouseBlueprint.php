<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

class ClickHouseBlueprint extends Blueprint
{
    /**
     * The columns for the ORDER BY clause (required for MergeTree).
     */
    public ?array $orderByColumns = null;

    /**
     * The PARTITION BY expression.
     */
    public ?string $partitionByExpression = null;

    /**
     * The PRIMARY KEY columns (if different from ORDER BY).
     */
    public ?array $primaryKeyColumns = null;

    /**
     * ClickHouse table settings.
     */
    public array $tableSettings = [];

    /**
     * The ON CLUSTER clause for distributed DDL.
     */
    public ?string $onCluster = null;

    /**
     * Set the table engine.
     *
     * @param string $engine e.g. 'MergeTree()', 'ReplacingMergeTree(ver)',
     *                       'SummingMergeTree(amount)', 'Memory', 'Log'
     */
    public function engine($engine): static
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Set the ORDER BY columns.
     * Required for MergeTree family engines.
     *
     * Usage: $table->orderBy('created_at', 'user_id');
     */
    public function orderBy(string ...$columns): static
    {
        $this->orderByColumns = $columns;
        return $this;
    }

    /**
     * Set the PARTITION BY expression.
     *
     * Usage: $table->partitionBy('toYYYYMM(created_at)');
     */
    public function partitionBy(string $expression): static
    {
        $this->partitionByExpression = $expression;
        return $this;
    }

    /**
     * Set explicit PRIMARY KEY columns.
     * If not set, ORDER BY columns are used as primary key.
     */
    public function primaryKey(string ...$columns): static
    {
        $this->primaryKeyColumns = $columns;
        return $this;
    }

    /**
     * Add a ClickHouse table setting.
     *
     * Usage: $table->setting('index_granularity', 8192);
     */
    public function setting(string $name, mixed $value): static
    {
        $this->tableSettings[$name] = $value;
        return $this;
    }

    /**
     * Set ON CLUSTER for distributed DDL.
     *
     * Usage: $table->onCluster('my_cluster');
     */
    public function onCluster(string $cluster): static
    {
        $this->onCluster = $cluster;
        return $this;
    }

    /**
     * UInt8 column.
     */
    public function uint8(string $column): Fluent
    {
        return $this->addColumn('unsignedTinyInteger', $column);
    }

    /**
     * UInt16 column.
     */
    public function uint16(string $column): Fluent
    {
        return $this->addColumn('unsignedSmallInteger', $column);
    }

    /**
     * UInt32 column.
     */
    public function uint32(string $column): Fluent
    {
        return $this->addColumn('unsignedInteger', $column);
    }

    /**
     * UInt64 column.
     */
    public function uint64(string $column): Fluent
    {
        return $this->addColumn('unsignedBigInteger', $column);
    }

    /**
     * Int8 column.
     */
    public function int8(string $column): Fluent
    {
        return $this->addColumn('tinyInteger', $column);
    }

    /**
     * Int16 column.
     */
    public function int16(string $column): Fluent
    {
        return $this->addColumn('smallInteger', $column);
    }

    /**
     * Int32 column.
     */
    public function int32(string $column): Fluent
    {
        return $this->addColumn('integer', $column);
    }

    /**
     * Int64 column.
     */
    public function int64(string $column): Fluent
    {
        return $this->addColumn('bigInteger', $column);
    }

    /**
     * Float32 column.
     */
    public function float32(string $column): Fluent
    {
        return $this->addColumn('float', $column);
    }

    /**
     * Float64 column.
     */
    public function float64(string $column): Fluent
    {
        return $this->addColumn('double', $column);
    }

    /**
     * FixedString(N) column.
     */
    public function fixedString(string $column, int $length): Fluent
    {
        return $this->addColumn('char', $column, compact('length'));
    }

    /**
     * IPv4 column.
     */
    public function ipv4(string $column): Fluent
    {
        return $this->addColumn('ipAddress', $column);
    }

    /**
     * LowCardinality(String) column — efficient for low-cardinality string columns.
     */
    public function lowCardinalityString(string $column): Fluent
    {
        return $this->addColumn('string', $column)->change();
        // TODO: needs custom type handler for LowCardinality wrapping
    }

    /**
     * DateTime64 with precision.
     */
    public function dateTime64(string $column, int $precision = 3): Fluent
    {
        return $this->addColumn('dateTime', $column, ['precision' => $precision]);
    }

    /**
     * Raw ClickHouse type — pass any type string directly.
     *
     * Usage:
     *   $table->clickhouseType('tags', 'Array(String)');
     *   $table->clickhouseType('metadata', 'Map(String, String)');
     *   $table->clickhouseType('coords', 'Tuple(Float64, Float64)');
     *   $table->clickhouseType('country', "LowCardinality(String)");
     *   $table->clickhouseType('amount', 'Decimal(18, 4)');
     */
    public function clickhouseType(string $column, string $type): Fluent
    {
        return $this->addColumn('clickhouseRaw', $column, ['clickhouse_type' => $type]);
    }

    public function arrayOf(string $column, string $elementType): Fluent
    {
        return $this->clickhouseType($column, "Array({$elementType})");
    }

    public function mapOf(string $column, string $keyType, string $valueType): Fluent
    {
        return $this->clickhouseType($column, "Map({$keyType}, {$valueType})");
    }

    public function tupleOf(string $column, string ...$types): Fluent
    {
        return $this->clickhouseType($column, 'Tuple(' . implode(', ', $types) . ')');
    }

    public function lowCardinality(string $column, string $innerType = 'String'): Fluent
    {
        return $this->clickhouseType($column, "LowCardinality({$innerType})");
    }

    public function ipv6(string $column): Fluent
    {
        return $this->clickhouseType($column, 'IPv6');
    }

    public function int128(string $column): Fluent
    {
        return $this->clickhouseType($column, 'Int128');
    }

    public function uint128(string $column): Fluent
    {
        return $this->clickhouseType($column, 'UInt128');
    }

    /**
     * Override: ClickHouse has no auto-increment.
     * Default to UInt64 instead.
     */
    public function id($column = 'id'): Fluent
    {
        return $this->uint64($column);
    }

    /**
     * Override: No foreign keys in ClickHouse.
     */
    public function foreign($columns, $name = null): Fluent
    {
        throw new \RuntimeException('ClickHouse does not support foreign key constraints.');
    }

    /**
     * Override: No standard indexes (ClickHouse uses skip indexes via ALTER TABLE).
     */
    public function index($columns = null, $name = null, $algorithm = null): Fluent
    {
        throw new \RuntimeException(
            'Use ClickHouse skip indexes via raw SQL: ALTER TABLE ... ADD INDEX'
        );
    }

    /**
     * Override: No unique constraints.
     */
    public function unique($columns = null, $name = null, $algorithm = null): Fluent
    {
        throw new \RuntimeException('ClickHouse does not support unique constraints.');
    }
}
