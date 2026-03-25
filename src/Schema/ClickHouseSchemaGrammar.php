<?php

namespace ClickHouse\Laravel\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class ClickHouseSchemaGrammar extends Grammar
{
    /**
     * ClickHouse column type mappings.
     */
    protected $modifiers = ['Nullable'];

    /**
     * Compile a CREATE TABLE statement.
     *
     * ClickHouse requires: CREATE TABLE name (columns) ENGINE = ... ORDER BY ...
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = implode(', ', $this->getColumns($blueprint));

        $onCluster = '';
        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->onCluster) {
            $onCluster = " ON CLUSTER {$blueprint->onCluster}";
        }

        $sql = "CREATE TABLE {$table}{$onCluster} ({$columns})";

        // Engine (required for ClickHouse)
        $engine = $blueprint->engine ?? 'MergeTree()';
        $sql .= " ENGINE = {$engine}";

        // ORDER BY (required for MergeTree family)
        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->orderByColumns) {
            $orderBy = implode(', ', array_map([$this, 'wrap'], $blueprint->orderByColumns));
            $sql .= " ORDER BY ({$orderBy})";
        } elseif (str_contains($engine, 'MergeTree')) {
            // Default to tuple() if no ORDER BY specified
            $sql .= " ORDER BY tuple()";
        }

        // PARTITION BY
        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->partitionByExpression) {
            $sql .= " PARTITION BY {$blueprint->partitionByExpression}";
        }

        // PRIMARY KEY (if different from ORDER BY)
        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->primaryKeyColumns) {
            $pk = implode(', ', array_map([$this, 'wrap'], $blueprint->primaryKeyColumns));
            $sql .= " PRIMARY KEY ({$pk})";
        }

        // SETTINGS
        if ($blueprint instanceof ClickHouseBlueprint && !empty($blueprint->tableSettings)) {
            $settings = collect($blueprint->tableSettings)
                ->map(fn($v, $k) => "{$k} = {$v}")
                ->implode(', ');
            $sql .= " SETTINGS {$settings}";
        }

        return $sql;
    }

    /**
     * Compile a DROP TABLE statement.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'DROP TABLE ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile an ADD COLUMN statement.
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->getColumns($blueprint);

        $statements = [];
        foreach ($columns as $column) {
            $statements[] = "ALTER TABLE {$table} ADD COLUMN {$column}";
        }

        return implode('; ', $statements);
    }

    /**
     * Compile a DROP COLUMN statement.
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = $this->prefixArray('DROP COLUMN', $this->wrapArray($command->columns));

        return 'ALTER TABLE ' . $table . ' ' . implode(', ', $columns);
    }

    /**
     * Compile a RENAME TABLE statement.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return 'RENAME TABLE ' . $this->wrapTable($blueprint) . ' TO ' . $this->wrapTable($command->to);
    }

    /**
     * Raw ClickHouse type — passes through any type string verbatim.
     */
    protected function typeClickhouseRaw(Fluent $column): string
    {
        return $column->clickhouse_type;
    }

    protected function typeString(Fluent $column): string
    {
        return 'String';
    }

    protected function typeInteger(Fluent $column): string
    {
        return 'Int32';
    }

    protected function typeBigInteger(Fluent $column): string
    {
        return 'Int64';
    }

    protected function typeSmallInteger(Fluent $column): string
    {
        return 'Int16';
    }

    protected function typeTinyInteger(Fluent $column): string
    {
        return 'Int8';
    }

    protected function typeMediumInteger(Fluent $column): string
    {
        return 'Int32';
    }

    protected function typeFloat(Fluent $column): string
    {
        return 'Float32';
    }

    protected function typeDouble(Fluent $column): string
    {
        return 'Float64';
    }

    protected function typeDecimal(Fluent $column): string
    {
        $precision = $column->total ?? 10;
        $scale = $column->places ?? 2;
        return "Decimal({$precision}, {$scale})";
    }

    protected function typeBoolean(Fluent $column): string
    {
        return 'UInt8';
    }

    protected function typeDate(Fluent $column): string
    {
        return 'Date';
    }

    protected function typeDateTime(Fluent $column): string
    {
        if ($column->precision) {
            return "DateTime64({$column->precision})";
        }
        return 'DateTime';
    }

    protected function typeDateTimeTz(Fluent $column): string
    {
        $tz = $column->timezone ?? 'UTC';
        if ($column->precision) {
            return "DateTime64({$column->precision}, '{$tz}')";
        }
        return "DateTime('{$tz}')";
    }

    protected function typeTimestamp(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeDateTimeTz($column);
    }

    protected function typeText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeMediumText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeLongText(Fluent $column): string
    {
        return 'String';
    }

    protected function typeJson(Fluent $column): string
    {
        return 'String'; // Store JSON as String, parse in application
    }

    protected function typeJsonb(Fluent $column): string
    {
        return 'String';
    }

    protected function typeBinary(Fluent $column): string
    {
        return 'String';
    }

    protected function typeUuid(Fluent $column): string
    {
        return 'UUID';
    }

    protected function typeIpAddress(Fluent $column): string
    {
        return 'IPv4';
    }

    protected function typeChar(Fluent $column): string
    {
        $length = $column->length ?? 1;
        return "FixedString({$length})";
    }

    protected function typeEnum(Fluent $column): string
    {
        $values = collect($column->allowed)
            ->map(fn($v, $i) => "'{$v}' = " . ($i + 1))
            ->implode(', ');
        return "Enum8({$values})";
    }

    protected function typeUnsignedTinyInteger(Fluent $column): string
    {
        return 'UInt8';
    }

    protected function typeUnsignedSmallInteger(Fluent $column): string
    {
        return 'UInt16';
    }

    protected function typeUnsignedMediumInteger(Fluent $column): string
    {
        return 'UInt32';
    }

    protected function typeUnsignedInteger(Fluent $column): string
    {
        return 'UInt32';
    }

    protected function typeUnsignedBigInteger(Fluent $column): string
    {
        return 'UInt64';
    }

    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        // ClickHouse wraps nullable types: Nullable(String)
        // This is handled by wrapping the type in getColumns
        return null;
    }

    /**
     * Get column definition SQL, wrapping in Nullable if needed.
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            $type = $this->getType($column);

            // Wrap in Nullable if column allows null
            if ($column->nullable) {
                $type = "Nullable({$type})";
            }

            // Default value
            $default = '';
            if (!is_null($column->default)) {
                $default = ' DEFAULT ' . $this->getDefaultValue($column->default);
            }

            // Compression codec
            $codec = '';
            if ($column->codec ?? null) {
                $codec = ' CODEC(' . $column->codec . ')';
            }

            $columns[] = $this->wrap($column) . ' ' . $type . $default . $codec;
        }

        return $columns;
    }

    /**
     * Wrap value for defaults.
     */
    protected function getDefaultValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return (string) $value;
    }

    /**
     * Backtick quoting for identifiers.
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
