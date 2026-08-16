<?php

namespace ClickHouse\Laravel\Schema;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/** @api */
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
     *
     * @param  Fluent<array-key, mixed>  $command
     *
     * @psalm-suppress MixedAssignment Table setting values are validated by ClickHouseSql::literal.
     * @psalm-suppress RedundantConditionGivenDocblockType Laravel's engine property is unset until configured.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $columns = implode(', ', $this->getColumns($blueprint));

        $onCluster = '';
        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->onCluster !== null) {
            $onCluster = ' ON CLUSTER '
                .ClickHouseSql::quoteIdentifier($blueprint->onCluster, 'cluster name');
        }

        $sql = "CREATE TABLE {$table}{$onCluster} ({$columns})";

        // Engine (required for ClickHouse)
        $engine = $blueprint instanceof ClickHouseBlueprint && $blueprint->engine !== null
            ? $blueprint->engine
            : 'MergeTree()';
        $sql .= " ENGINE = {$engine}";

        // PARTITION BY
        if (
            $blueprint instanceof ClickHouseBlueprint
            && $blueprint->partitionByExpression !== null
        ) {
            $sql .= " PARTITION BY {$blueprint->partitionByExpression}";
        }

        // PRIMARY KEY (if different from ORDER BY)
        if (
            $blueprint instanceof ClickHouseBlueprint
            && $blueprint->primaryKeyColumns !== null
            && $blueprint->primaryKeyColumns !== []
        ) {
            $pk = implode(', ', array_map(
                fn (string $column): string => $this->wrap($column),
                $blueprint->primaryKeyColumns,
            ));
            $sql .= " PRIMARY KEY ({$pk})";
        }

        // ORDER BY (required for MergeTree family)
        if (
            $blueprint instanceof ClickHouseBlueprint
            && $blueprint->orderByColumns !== null
            && $blueprint->orderByColumns !== []
        ) {
            $orderBy = implode(', ', array_map(
                fn (string $column): string => $this->wrap($column),
                $blueprint->orderByColumns,
            ));
            $sql .= " ORDER BY ({$orderBy})";
        } elseif (str_contains($engine, 'MergeTree')) {
            $sql .= ' ORDER BY tuple()';
        }

        if (
            $blueprint instanceof ClickHouseBlueprint
            && $blueprint->sampleByExpression !== null
        ) {
            $sql .= " SAMPLE BY {$blueprint->sampleByExpression}";
        }

        if (
            $blueprint instanceof ClickHouseBlueprint
            && $blueprint->ttlExpression !== null
        ) {
            $sql .= " TTL {$blueprint->ttlExpression}";
        }

        // SETTINGS
        if ($blueprint instanceof ClickHouseBlueprint && ! empty($blueprint->tableSettings)) {
            $settings = [];

            foreach ($blueprint->tableSettings as $name => $value) {
                $setting = ClickHouseSql::settingName($name);
                $settings[] = $setting
                    .' = '.ClickHouseSql::literal($value, "table setting {$name}");
            }

            $sql .= ' SETTINGS '.implode(', ', $settings);
        }

        if ($blueprint instanceof ClickHouseBlueprint && $blueprint->tableComment !== null) {
            $sql .= ' COMMENT '.ClickHouseSql::quoteString($blueprint->tableComment);
        }

        return $sql;
    }

    /**
     * Compile a DROP TABLE statement.
     *
     * @param  Fluent<array-key, mixed>  $command
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'DROP TABLE '.$this->wrapTable($blueprint).$this->compileBlueprintCluster($blueprint);
    }

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     *
     * @param  Fluent<array-key, mixed>  $command
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'DROP TABLE IF EXISTS '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint);
    }

    /**
     * Compile an ADD COLUMN statement.
     *
     * @param  Fluent<array-key, mixed>  $command
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $column = $this->getCommandColumn($command);

        return "ALTER TABLE {$table}"
            .$this->compileBlueprintCluster($blueprint)
            ." ADD COLUMN {$column}";
    }

    /**
     * Compile a DROP COLUMN statement.
     *
     * @param  Fluent<array-key, mixed>  $command
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $rawColumns = $command->get('columns', []);

        if (! is_array($rawColumns)) {
            throw new InvalidArgumentException(
                'ClickHouse DROP COLUMN columns must be an array.'
            );
        }

        $columns = [];
        foreach ($rawColumns as $column) {
            if (! is_string($column)) {
                throw new InvalidArgumentException(
                    'ClickHouse DROP COLUMN names must be strings.'
                );
            }

            $columns[] = $column;
        }

        if ($columns === []) {
            throw new InvalidArgumentException(
                'ClickHouse DROP COLUMN requires at least one column.'
            );
        }

        $columns = $this->prefixArray('DROP COLUMN', $this->wrapArray($columns));

        return 'ALTER TABLE '.$table
            .$this->compileBlueprintCluster($blueprint)
            .' '.implode(', ', $columns);
    }

    /**
     * Compile a RENAME TABLE statement.
     *
     * @param  Fluent<array-key, mixed>  $command
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        return 'RENAME TABLE '.$this->wrapTable($blueprint)
            .' TO '.$this->wrapTable($this->stringAttribute($command, 'to'))
            .$this->compileBlueprintCluster($blueprint);
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' RENAME COLUMN '.$this->wrap($this->stringAttribute($command, 'from'))
            .' TO '.$this->wrap($this->stringAttribute($command, 'to'));
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileChange(Blueprint $blueprint, Fluent $command): string
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' MODIFY COLUMN '.$this->getCommandColumn($command);
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileAddSkipIndex(Blueprint $blueprint, Fluent $command): string
    {
        $index = $this->stringAttribute($command, 'index');
        $expression = ClickHouseSql::safeExpression(
            $this->stringAttribute($command, 'expression'),
            'skip index expression',
        );
        $type = ClickHouseSql::safeExpression(
            $this->stringAttribute($command, 'type'),
            'skip index type',
        );
        $granularity = $this->integerAttribute($command, 'granularity');

        if ($granularity < 1) {
            throw new InvalidArgumentException(
                'ClickHouse skip index granularity must be at least 1.'
            );
        }

        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' ADD INDEX '.$this->wrap($index)
            .' '.$expression
            .' TYPE '.$type
            .' GRANULARITY '.$granularity;
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileDropSkipIndex(Blueprint $blueprint, Fluent $command): string
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' DROP INDEX '.$this->wrap($this->stringAttribute($command, 'index'));
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileAddProjection(Blueprint $blueprint, Fluent $command): string
    {
        $select = ClickHouseSql::safeExpression(
            $this->stringAttribute($command, 'select'),
            'projection SELECT expression',
        );

        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' ADD PROJECTION '.$this->wrap($this->stringAttribute($command, 'projection'))
            .' ('.$select.')';
    }

    /** @param Fluent<array-key, mixed> $command */
    public function compileDropProjection(Blueprint $blueprint, Fluent $command): string
    {
        return 'ALTER TABLE '.$this->wrapTable($blueprint)
            .$this->compileBlueprintCluster($blueprint)
            .' DROP PROJECTION '.$this->wrap($this->stringAttribute($command, 'projection'));
    }

    /**
     * Pass through any raw ClickHouse type string verbatim.
     */
    protected function typeClickhouseRaw(ClickHouseColumnDefinition $column): string
    {
        return ClickHouseSql::safeExpression(
            $this->stringAttribute($column, 'clickhouse_type'),
            'column type',
        );
    }

    protected function typeString(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeInteger(ClickHouseColumnDefinition $column): string
    {
        return $this->booleanAttribute($column, 'unsigned', false) ? 'UInt32' : 'Int32';
    }

    protected function typeBigInteger(ClickHouseColumnDefinition $column): string
    {
        return $this->booleanAttribute($column, 'unsigned', false) ? 'UInt64' : 'Int64';
    }

    protected function typeSmallInteger(ClickHouseColumnDefinition $column): string
    {
        return $this->booleanAttribute($column, 'unsigned', false) ? 'UInt16' : 'Int16';
    }

    protected function typeTinyInteger(ClickHouseColumnDefinition $column): string
    {
        return $this->booleanAttribute($column, 'unsigned', false) ? 'UInt8' : 'Int8';
    }

    protected function typeMediumInteger(ClickHouseColumnDefinition $column): string
    {
        return $this->booleanAttribute($column, 'unsigned', false) ? 'UInt32' : 'Int32';
    }

    protected function typeFloat(ClickHouseColumnDefinition $column): string
    {
        return 'Float32';
    }

    protected function typeDouble(ClickHouseColumnDefinition $column): string
    {
        return 'Float64';
    }

    protected function typeDecimal(ClickHouseColumnDefinition $column): string
    {
        $precision = $this->integerAttribute($column, 'total', 10);
        $scale = $this->integerAttribute($column, 'places', 2);

        if ($precision < 1 || $precision > 76 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException(
                'ClickHouse Decimal requires precision 1-76 and scale between 0 and precision.'
            );
        }

        return "Decimal({$precision}, {$scale})";
    }

    protected function typeBoolean(ClickHouseColumnDefinition $column): string
    {
        return 'Bool';
    }

    protected function typeDate(ClickHouseColumnDefinition $column): string
    {
        return 'Date';
    }

    protected function typeDate32(ClickHouseColumnDefinition $column): string
    {
        return 'Date32';
    }

    protected function typeDateTime(ClickHouseColumnDefinition $column): string
    {
        $precision = $this->nullableIntegerAttribute($column, 'precision');
        if (
            $this->booleanAttribute($column, 'clickhouse_datetime64', false)
            || ($precision !== null && $precision > 0)
        ) {
            $precision ??= 0;
            $this->validateTemporalPrecision($precision);

            return "DateTime64({$precision})";
        }

        return 'DateTime';
    }

    protected function typeDateTimeTz(ClickHouseColumnDefinition $column): string
    {
        $tz = $this->stringAttribute($column, 'timezone', 'UTC');
        $quotedTimezone = ClickHouseSql::quoteString($tz);
        $precision = $this->nullableIntegerAttribute($column, 'precision');

        if (
            $this->booleanAttribute($column, 'clickhouse_datetime64', false)
            || ($precision !== null && $precision > 0)
        ) {
            $precision ??= 0;
            $this->validateTemporalPrecision($precision);

            return "DateTime64({$precision}, {$quotedTimezone})";
        }

        return "DateTime({$quotedTimezone})";
    }

    protected function typeTimestamp(ClickHouseColumnDefinition $column): string
    {
        return $this->typeDateTime($column);
    }

    protected function typeTimestampTz(ClickHouseColumnDefinition $column): string
    {
        return $this->typeDateTimeTz($column);
    }

    protected function typeTime(ClickHouseColumnDefinition $column): string
    {
        $precision = $this->nullableIntegerAttribute($column, 'precision');
        if ($precision !== null && $precision > 0) {
            $this->validateTemporalPrecision($precision);

            return "Time64({$precision})";
        }

        return 'Time';
    }

    protected function typeTimeTz(ClickHouseColumnDefinition $column): string
    {
        return $this->typeTime($column);
    }

    protected function typeTime64(ClickHouseColumnDefinition $column): string
    {
        $precision = $this->integerAttribute($column, 'precision', 3);
        $this->validateTemporalPrecision($precision);

        return "Time64({$precision})";
    }

    protected function typeText(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeMediumText(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeLongText(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeJson(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeJsonb(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeBinary(ClickHouseColumnDefinition $column): string
    {
        return 'String';
    }

    protected function typeUuid(ClickHouseColumnDefinition $column): string
    {
        return 'UUID';
    }

    protected function typeIpAddress(ClickHouseColumnDefinition $column): string
    {
        return 'IPv4';
    }

    protected function typeChar(ClickHouseColumnDefinition $column): string
    {
        $length = $this->integerAttribute($column, 'length', 1);

        if ($length < 1) {
            throw new InvalidArgumentException('ClickHouse FixedString length must be at least 1.');
        }

        return "FixedString({$length})";
    }

    /** @psalm-suppress MixedAssignment Enum entries are validated in the loop. */
    protected function typeEnum(ClickHouseColumnDefinition $column): string
    {
        $allowed = (array) $column->get('allowed', []);

        if ($allowed === []) {
            throw new InvalidArgumentException('ClickHouse Enum8 requires at least one value.');
        }

        /** @var list<string|int> $normalizedAllowed */
        $normalizedAllowed = [];

        foreach ($allowed as $value) {
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException(
                    'ClickHouse Enum8 values must be strings or integers.'
                );
            }

            $normalizedAllowed[] = $value;
        }

        if (
            count(array_unique(array_map(
                static fn (string|int $value): string => (string) $value,
                $normalizedAllowed,
            ))) !== count($normalizedAllowed)
        ) {
            throw new InvalidArgumentException('ClickHouse Enum8 values must be unique.');
        }

        if (count($normalizedAllowed) > 127) {
            throw new InvalidArgumentException('ClickHouse Enum8 supports at most 127 positive values.');
        }

        $values = [];

        foreach ($normalizedAllowed as $index => $value) {
            $values[] = ClickHouseSql::quoteString((string) $value)
                .' = '.(string) ($index + 1);
        }

        return 'Enum8('.implode(', ', $values).')';
    }

    protected function typeUnsignedTinyInteger(ClickHouseColumnDefinition $column): string
    {
        return 'UInt8';
    }

    protected function typeUnsignedSmallInteger(ClickHouseColumnDefinition $column): string
    {
        return 'UInt16';
    }

    protected function typeUnsignedMediumInteger(ClickHouseColumnDefinition $column): string
    {
        return 'UInt32';
    }

    protected function typeUnsignedInteger(ClickHouseColumnDefinition $column): string
    {
        return 'UInt32';
    }

    protected function typeUnsignedBigInteger(ClickHouseColumnDefinition $column): string
    {
        return 'UInt64';
    }

    /** @param Fluent<array-key, mixed> $column */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        // ClickHouse wraps nullable types: Nullable(String)
        // This is handled by wrapping the type in getColumns
        return null;
    }

    /**
     * Get column definition SQL, wrapping in Nullable if needed.
     *
     * @return list<string>
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            if (! $column instanceof ClickHouseColumnDefinition) {
                throw new InvalidArgumentException(
                    'ClickHouse schema columns must use ClickHouseColumnDefinition.'
                );
            }

            $columns[] = $this->getColumnDefinition($column);
        }

        return $columns;
    }

    /**
     * Wrap value for defaults.
     */
    protected function getDefaultValue($value): string
    {
        if ($value instanceof Expression) {
            $expression = $value->getValue($this);

            if (is_int($expression) || is_float($expression)) {
                return ClickHouseSql::literal($expression, 'column default');
            }

            return $expression;
        }

        return ClickHouseSql::literal($value, 'column default');
    }

    /**
     * Backtick quoting for identifiers.
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    /** @psalm-suppress MixedAssignment Fluent attributes are validated before compilation. */
    protected function getColumnDefinition(ClickHouseColumnDefinition $column): string
    {
        $type = $this->getType($column);
        $nullable = $this->booleanAttribute($column, 'nullable', false);
        $lowCardinality = $this->booleanAttribute($column, 'low_cardinality', false);

        if ($lowCardinality) {
            $type = $nullable
                ? "LowCardinality(Nullable({$type}))"
                : "LowCardinality({$type})";
        } elseif ($nullable && preg_match('/\ALowCardinality\((.*)\)\z/s', $type, $matches)) {
            /** @psalm-suppress PossiblyUndefinedIntArrayOffset The capture group is required. */
            $innerType = $matches[1];
            $type = str_starts_with($innerType, 'Nullable(')
                ? $type
                : "LowCardinality(Nullable({$innerType}))";
        } elseif ($nullable) {
            $type = "Nullable({$type})";
        }

        $clauses = [];
        $expressions = array_filter([
            'DEFAULT' => $column->get('default'),
            'MATERIALIZED' => $column->get('materialized'),
            'ALIAS' => $column->get('alias'),
            'EPHEMERAL' => $column->get('ephemeral'),
        ], fn ($value) => $value !== null);

        if (count($expressions) > 1) {
            throw new InvalidArgumentException(
                'A ClickHouse column may define only one of DEFAULT, MATERIALIZED, ALIAS, or EPHEMERAL.'
            );
        }

        foreach ($expressions as $keyword => $expression) {
            if ($keyword === 'EPHEMERAL' && $expression === true) {
                $clauses[] = 'EPHEMERAL';

                continue;
            }

            $clauses[] = $keyword.' '.($keyword === 'DEFAULT'
                ? $this->getDefaultValue($expression)
                : $this->compileColumnExpression($expression, strtolower($keyword)));
        }

        $codec = $column->get('codec');
        if ($codec !== null) {
            $clauses[] = 'CODEC('
                .ClickHouseSql::safeExpression(
                    $this->stringAttribute($column, 'codec'),
                    'column codec',
                )
                .')';
        }

        $ttl = $column->get('ttl');
        if ($ttl !== null) {
            $clauses[] = 'TTL '.$this->compileColumnExpression($ttl, 'column TTL');
        }

        $comment = $column->get('comment');
        if ($comment !== null) {
            $clauses[] = 'COMMENT '.ClickHouseSql::quoteString(
                $this->stringAttribute($column, 'comment')
            );
        }

        return trim($this->wrap($column).' '.$type.' '.implode(' ', $clauses));
    }

    protected function compileColumnExpression(mixed $expression, string $label): string
    {
        if ($expression instanceof Expression) {
            $value = $expression->getValue($this);

            if (is_int($value) || is_float($value)) {
                return ClickHouseSql::literal($value, $label);
            }

            return $value;
        }

        if (! is_string($expression)) {
            throw new InvalidArgumentException("ClickHouse {$label} must be a SQL expression.");
        }

        return ClickHouseSql::safeExpression($expression, $label);
    }

    /** @param Fluent<array-key, mixed> $command */
    protected function getCommandColumn(Fluent $command): string
    {
        $column = $command->get('column');

        if (! $column instanceof ClickHouseColumnDefinition) {
            throw new InvalidArgumentException('ClickHouse column command is missing its column.');
        }

        return $this->getColumnDefinition($column);
    }

    protected function compileBlueprintCluster(Blueprint $blueprint): string
    {
        if (! $blueprint instanceof ClickHouseBlueprint || $blueprint->onCluster === null) {
            return '';
        }

        return ' ON CLUSTER '
            .ClickHouseSql::quoteIdentifier($blueprint->onCluster, 'cluster name');
    }

    private function validateTemporalPrecision(int $precision): void
    {
        if ($precision < 0 || $precision > 9) {
            throw new InvalidArgumentException(
                'ClickHouse DateTime64/Time64 precision must be between 0 and 9.'
            );
        }
    }

    /**
     * @param  Fluent<array-key, mixed>  $fluent
     *
     * @psalm-suppress MixedAssignment Fluent attributes require runtime validation.
     */
    private function stringAttribute(
        Fluent $fluent,
        string $key,
        ?string $default = null,
    ): string {
        $value = $fluent->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "ClickHouse schema attribute {$key} must be a string."
            );
        }

        return $value;
    }

    /**
     * @param  Fluent<array-key, mixed>  $fluent
     *
     * @psalm-suppress MixedAssignment Fluent attributes require runtime validation.
     */
    private function integerAttribute(
        Fluent $fluent,
        string $key,
        ?int $default = null,
    ): int {
        $value = $fluent->get($key, $default);
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($integer)) {
            throw new InvalidArgumentException(
                "ClickHouse schema attribute {$key} must be an integer."
            );
        }

        return $integer;
    }

    private function nullableIntegerAttribute(
        ClickHouseColumnDefinition $column,
        string $key,
    ): ?int {
        return $column->get($key) === null
            ? null
            : $this->integerAttribute($column, $key);
    }

    private function booleanAttribute(
        ClickHouseColumnDefinition $column,
        string $key,
        bool $default,
    ): bool {
        /** @psalm-suppress MixedAssignment Fluent attributes require runtime validation. */
        $value = $column->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw new InvalidArgumentException(
            "ClickHouse schema attribute {$key} must be a boolean."
        );
    }
}
