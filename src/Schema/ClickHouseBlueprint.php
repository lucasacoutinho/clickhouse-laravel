<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;

/**
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel owns inherited blueprint state.
 */
class ClickHouseBlueprint extends Blueprint
{
    /**
     * The columns for the ORDER BY clause (required for MergeTree).
     *
     * @var list<string>|null
     */
    public ?array $orderByColumns = null;

    /**
     * The PARTITION BY expression.
     */
    public ?string $partitionByExpression = null;

    /**
     * The PRIMARY KEY columns (if different from ORDER BY).
     *
     * @var list<string>|null
     */
    public ?array $primaryKeyColumns = null;

    /**
     * ClickHouse table settings.
     *
     * @var array<string, mixed>
     */
    public array $tableSettings = [];

    /**
     * The ON CLUSTER clause for distributed DDL.
     */
    public ?string $onCluster = null;

    public ?string $sampleByExpression = null;

    public ?string $ttlExpression = null;

    public ?string $tableComment = null;

    /**
     * Set the table engine.
     *
     * @param  string  $engine  e.g. 'MergeTree()', 'ReplacingMergeTree(ver)',
     *                          'SummingMergeTree(amount)', 'Memory', 'Log'
     *
     * @psalm-suppress ImplementedReturnTypeMismatch Laravel documents engine() as a magic void method.
     */
    public function engine($engine): static
    {
        $this->engine = ClickHouseSql::safeExpression($engine, 'table engine');

        return $this;
    }

    /**
     * Set the ORDER BY columns.
     * Required for MergeTree family engines.
     *
     * Usage: $table->orderBy('created_at', 'user_id');
     */
    /** @param array<array-key, mixed>|string ...$columns */
    public function orderBy(array|string ...$columns): static
    {
        $this->orderByColumns = $this->normalizeColumns($columns, 'ORDER BY');

        return $this;
    }

    /**
     * Set the PARTITION BY expression.
     *
     * Usage: $table->partitionBy('toYYYYMM(created_at)');
     */
    public function partitionBy(string $expression): static
    {
        $this->partitionByExpression = ClickHouseSql::safeExpression(
            $expression,
            'PARTITION BY expression',
        );

        return $this;
    }

    /**
     * Set explicit PRIMARY KEY columns.
     * If not set, ORDER BY columns are used as primary key.
     */
    /** @param array<array-key, mixed>|string ...$columns */
    public function primaryKey(array|string ...$columns): static
    {
        $this->primaryKeyColumns = $this->normalizeColumns($columns, 'PRIMARY KEY');

        return $this;
    }

    /**
     * Add a ClickHouse table setting.
     *
     * Usage: $table->setting('index_granularity', 8192);
     */
    public function setting(string $name, mixed $value): static
    {
        ClickHouseSql::settingName($name);
        ClickHouseSql::literal($value, "table setting {$name}");
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
        $this->onCluster = ClickHouseSql::nonEmpty($cluster, 'cluster name');

        return $this;
    }

    public function sampleBy(string $expression): static
    {
        $this->sampleByExpression = ClickHouseSql::safeExpression(
            $expression,
            'SAMPLE BY expression',
        );

        return $this;
    }

    public function ttl(string $expression): static
    {
        $this->ttlExpression = ClickHouseSql::safeExpression($expression, 'table TTL expression');

        return $this;
    }

    public function tableComment(string $comment): static
    {
        $this->tableComment = $comment;

        return $this;
    }

    /**
     * Add a ClickHouse-aware column definition so native modifiers are
     * discoverable by IDEs and static analyzers.
     *
     * @param  string  $type
     * @param  string  $name
     * @param  array<array-key, mixed>  $parameters
     */
    public function addColumn($type, $name, array $parameters = []): ClickHouseColumnDefinition
    {
        $definition = new ClickHouseColumnDefinition(
            array_merge(
                ['type' => $type, 'name' => $name],
                $parameters,
            ),
        );

        $this->addColumnDefinition($definition);

        return $definition;
    }

    /**
     * UInt8 column.
     */
    public function uint8(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('unsignedTinyInteger', $column);
    }

    /**
     * UInt16 column.
     */
    public function uint16(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('unsignedSmallInteger', $column);
    }

    /**
     * UInt32 column.
     */
    public function uint32(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('unsignedInteger', $column);
    }

    /**
     * UInt64 column.
     */
    public function uint64(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('unsignedBigInteger', $column);
    }

    /**
     * Int8 column.
     */
    public function int8(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('tinyInteger', $column);
    }

    /**
     * Int16 column.
     */
    public function int16(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('smallInteger', $column);
    }

    /**
     * Int32 column.
     */
    public function int32(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('integer', $column);
    }

    /**
     * Int64 column.
     */
    public function int64(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('bigInteger', $column);
    }

    /**
     * Float32 column.
     */
    public function float32(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('float', $column);
    }

    /**
     * Float64 column.
     */
    public function float64(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('double', $column);
    }

    /**
     * FixedString(N) column.
     */
    public function fixedString(string $column, int $length): ClickHouseColumnDefinition
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('ClickHouse FixedString length must be at least 1.');
        }

        return $this->addColumn('char', $column, compact('length'));
    }

    /**
     * IPv4 column.
     */
    public function ipv4(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('ipAddress', $column);
    }

    /**
     * Add a LowCardinality(String) column for low-cardinality string values.
     */
    public function lowCardinalityString(string $column): ClickHouseColumnDefinition
    {
        return $this->clickhouseType($column, 'LowCardinality(String)');
    }

    public function date32(string $column): ClickHouseColumnDefinition
    {
        return $this->addColumn('date32', $column);
    }

    public function time64(string $column, int $precision = 3): ClickHouseColumnDefinition
    {
        if ($precision < 0 || $precision > 9) {
            throw new \InvalidArgumentException('ClickHouse Time64 precision must be between 0 and 9.');
        }

        return $this->addColumn('time64', $column, [
            'precision' => $precision,
            'clickhouse_time64' => true,
        ]);
    }

    /**
     * Native ClickHouse JSON.
     *
     * ext-pdo_clickhouse 1.2 cannot decode Dynamic subcolumns directly. Prefer
     * Laravel's json()/jsonb() String mapping unless queries cast JSON output to
     * a supported scalar type.
     */
    public function nativeJson(string $column): ClickHouseColumnDefinition
    {
        return $this->clickhouseType($column, 'JSON');
    }

    /**
     * DateTime64 with precision.
     */
    public function dateTime64(string $column, int $precision = 3): ClickHouseColumnDefinition
    {
        if ($precision < 0 || $precision > 9) {
            throw new \InvalidArgumentException('ClickHouse DateTime64 precision must be between 0 and 9.');
        }

        return $this->addColumn('dateTime', $column, [
            'precision' => $precision,
            'clickhouse_datetime64' => true,
        ]);
    }

    /**
     * Pass any raw ClickHouse type string directly.
     *
     * Usage:
     *   $table->clickhouseType('tags', 'Array(String)');
     *   $table->clickhouseType('metadata', 'Map(String, String)');
     *   $table->clickhouseType('coords', 'Tuple(Float64, Float64)');
     *   $table->clickhouseType('country', "LowCardinality(String)");
     *   $table->clickhouseType('amount', 'Decimal(18, 4)');
     */
    public function clickhouseType(string $column, string $type): ClickHouseColumnDefinition
    {
        ClickHouseSql::safeExpression($type, 'column type');

        return $this->addColumn('clickhouseRaw', $column, ['clickhouse_type' => $type]);
    }

    public function arrayOf(string $column, string $elementType): ClickHouseColumnDefinition
    {
        ClickHouseSql::safeExpression($elementType, 'array element type');

        return $this->clickhouseType($column, "Array({$elementType})");
    }

    public function array(string $column, string $elementType): ClickHouseColumnDefinition
    {
        return $this->arrayOf($column, $elementType);
    }

    public function mapOf(string $column, string $keyType, string $valueType): ClickHouseColumnDefinition
    {
        ClickHouseSql::safeExpression($keyType, 'map key type');
        ClickHouseSql::safeExpression($valueType, 'map value type');

        return $this->clickhouseType($column, "Map({$keyType}, {$valueType})");
    }

    public function tupleOf(string $column, string ...$types): ClickHouseColumnDefinition
    {
        if ($types === []) {
            throw new \InvalidArgumentException('ClickHouse Tuple requires at least one type.');
        }

        foreach ($types as $type) {
            ClickHouseSql::safeExpression($type, 'tuple element type');
        }

        return $this->clickhouseType($column, 'Tuple('.implode(', ', $types).')');
    }

    public function lowCardinality(string $column, string $innerType = 'String'): ClickHouseColumnDefinition
    {
        ClickHouseSql::safeExpression($innerType, 'LowCardinality inner type');

        return $this->clickhouseType($column, "LowCardinality({$innerType})");
    }

    public function ipv6(string $column): ClickHouseColumnDefinition
    {
        return $this->clickhouseType($column, 'IPv6');
    }

    public function int128(string $column): ClickHouseColumnDefinition
    {
        return $this->clickhouseType($column, 'Int128');
    }

    public function uint128(string $column): ClickHouseColumnDefinition
    {
        return $this->clickhouseType($column, 'UInt128');
    }

    /** @return Fluent<array-key, mixed> */
    public function skipIndex(
        string $name,
        string $expression,
        string $type,
        int $granularity = 1,
    ): Fluent {
        ClickHouseSql::nonEmpty($name, 'skip index name');
        ClickHouseSql::safeExpression($expression, 'skip index expression');
        ClickHouseSql::safeExpression($type, 'skip index type');

        if ($granularity < 1) {
            throw new \InvalidArgumentException('ClickHouse skip index granularity must be at least 1.');
        }

        $index = $name;

        return $this->addCommand(
            'addSkipIndex',
            compact('index', 'expression', 'type', 'granularity'),
        );
    }

    /** @return Fluent<array-key, mixed> */
    public function dropSkipIndex(string $name): Fluent
    {
        ClickHouseSql::nonEmpty($name, 'skip index name');
        $index = $name;

        return $this->addCommand('dropSkipIndex', compact('index'));
    }

    /** @return Fluent<array-key, mixed> */
    public function projection(string $name, string $select): Fluent
    {
        ClickHouseSql::nonEmpty($name, 'projection name');
        ClickHouseSql::safeExpression($select, 'projection SELECT expression');
        $projection = $name;

        return $this->addCommand('addProjection', compact('projection', 'select'));
    }

    /** @return Fluent<array-key, mixed> */
    public function dropProjection(string $name): Fluent
    {
        ClickHouseSql::nonEmpty($name, 'projection name');
        $projection = $name;

        return $this->addCommand('dropProjection', compact('projection'));
    }

    /**
     * Override: ClickHouse has no auto-increment.
     * Default to UInt64 instead.
     */
    public function id($column = 'id'): ClickHouseColumnDefinition
    {
        return $this->uint64($column);
    }

    /**
     * Override: No foreign keys in ClickHouse.
     */
    /**
     * @param  array<array-key, mixed>|string  $columns
     * @param  string|null  $name
     */
    public function foreign($columns, $name = null): never
    {
        throw new \RuntimeException('ClickHouse does not support foreign key constraints.');
    }

    /**
     * Override: No standard indexes (ClickHouse uses skip indexes via ALTER TABLE).
     */
    /**
     * @param  array<array-key, mixed>|string|null  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     */
    public function index($columns = null, $name = null, $algorithm = null): never
    {
        throw new \RuntimeException(
            'Use $table->skipIndex() for ClickHouse data-skipping indexes.'
        );
    }

    /**
     * Override: No unique constraints.
     */
    /**
     * @param  array<array-key, mixed>|string|null  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     */
    public function unique($columns = null, $name = null, $algorithm = null): never
    {
        throw new \RuntimeException('ClickHouse does not support unique constraints.');
    }

    /**
     * @param  array<array-key, array<array-key, mixed>|string>  $columns
     * @return list<string>
     *
     * @psalm-suppress MixedAssignment Column entries are validated in the loop.
     */
    private function normalizeColumns(array $columns, string $clause): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            foreach ((array) $column as $name) {
                if (! is_string($name) || trim($name) === '') {
                    throw new \InvalidArgumentException(
                        "ClickHouse {$clause} columns must be non-empty strings."
                    );
                }

                $normalized[] = $name;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException(
                "ClickHouse {$clause} requires at least one column."
            );
        }

        return $normalized;
    }
}
