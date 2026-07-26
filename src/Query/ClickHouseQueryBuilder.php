<?php

namespace ClickHouse\Laravel\Query;

use ClickHouse\Laravel\ClickHouseConnection;
use ClickHouse\Laravel\Query\Concerns\HasArrayJoin;
use ClickHouse\Laravel\Query\Concerns\HasClickHouseJoin;
use ClickHouse\Laravel\Query\Concerns\HasClickHousePredicates;
use ClickHouse\Laravel\Query\Concerns\HasCommonTableExpressions;
use ClickHouse\Laravel\Query\Concerns\HasFinal;
use ClickHouse\Laravel\Query\Concerns\HasFormat;
use ClickHouse\Laravel\Query\Concerns\HasLimitBy;
use ClickHouse\Laravel\Query\Concerns\HasOnCluster;
use ClickHouse\Laravel\Query\Concerns\HasPreWhere;
use ClickHouse\Laravel\Query\Concerns\HasRemote;
use ClickHouse\Laravel\Query\Concerns\HasSample;
use ClickHouse\Laravel\Query\Concerns\HasSetOperations;
use ClickHouse\Laravel\Query\Concerns\HasSettings;
use ClickHouse\Laravel\Query\Concerns\HasWithFill;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * @api
 *
 * @psalm-suppress PropertyNotSetInConstructor Laravel initializes inherited query state.
 */
class ClickHouseQueryBuilder extends Builder
{
    use HasArrayJoin;
    use HasClickHouseJoin;
    use HasClickHousePredicates;
    use HasCommonTableExpressions;
    use HasFinal;
    use HasFormat;
    use HasLimitBy;
    use HasOnCluster;
    use HasPreWhere;
    use HasRemote;
    use HasSample;
    use HasSetOperations;
    use HasSettings;
    use HasWithFill;

    /**
     * Keep bindings in the same order as ClickHouse emits SQL clauses.
     *
     * PREWHERE must have its own bucket because it is compiled before WHERE,
     * regardless of the order in which the fluent methods were called.
     */
    public $bindings = [
        'with' => [],
        'select' => [],
        'from' => [],
        'clickhouseJoin' => [],
        'join' => [],
        'prewhere' => [],
        'partition' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
        'unionOrder' => [],
    ];

    // Properties used by Grammar::compileComponents() dispatch.
    // Declared explicitly to avoid PHP 8.2 dynamic property deprecation.
    /** @var list<array<string, mixed>> */
    public array $arrayjoin = [];

    /** @var list<array<string, mixed>> */
    public array $withqueries = [];

    /** @var array<string, bool|float|int|string|null> */
    public array $settings = [];

    /** @var list<array<string, mixed>> */
    public array $prewhere = [];

    /** @var array{}|array{count: int, columns: list<string>} */
    public array $limitby = [];

    /** @var list<array<string, mixed>> */
    public array $clickhousejoin = [];

    public ?string $format = null;

    /**
     * Add a binding, including ClickHouse-specific binding buckets.
     *
     * @param  mixed  $value
     * @param  string  $type
     *
     * @psalm-suppress MixedArgument Laravel's binding map is extended with ClickHouse buckets.
     * @psalm-suppress MixedArrayAccess Laravel's binding map is extended with ClickHouse buckets.
     * @psalm-suppress MixedArrayAssignment Laravel's binding map is extended with ClickHouse buckets.
     * @psalm-suppress MixedAssignment Laravel's binding map is extended with ClickHouse buckets.
     */
    public function addBinding($value, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_map(
                $this->castBinding(...),
                array_merge($this->bindings[$type], $value),
            ));
        } else {
            $this->bindings[$type][] = $this->castBinding($value);
        }

        return $this;
    }

    /**
     * Set bindings, including ClickHouse-specific binding buckets.
     *
     * @param  list<mixed>  $bindings
     * @param  string  $type
     *
     * @psalm-suppress MixedArgument Laravel's binding map is extended with ClickHouse buckets.
     * @psalm-suppress MixedArrayAssignment Laravel's binding map is extended with ClickHouse buckets.
     * @psalm-suppress MixedAssignment Laravel's binding map is extended with ClickHouse buckets.
     */
    public function setBindings(array $bindings, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = array_map(
            $this->castBinding(...),
            $bindings,
        );

        return $this;
    }

    /**
     * Insert rows in chunks. ClickHouse performs best with batches of 10k+ rows.
     *
     * Usage:
     *   DB::table('events')->insertChunked($millionRows, 50000);
     *   Event::query()->insertChunked($rows);
     */
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertChunked(array $values, int $chunkSize = 10000): bool
    {
        if (empty($values)) {
            return true;
        }

        if ($chunkSize < 1) {
            throw new InvalidArgumentException('ClickHouse insert chunk size must be at least 1.');
        }

        foreach (array_chunk($values, $chunkSize) as $chunk) {
            if (! $this->insert($chunk)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete rows using either a classic ALTER mutation or lightweight DELETE.
     */
    public function delete(
        $id = null,
        ?bool $lightweight = null,
        mixed $partition = null,
    ): int {
        if ($id !== null) {
            if (! is_string($this->from)) {
                throw new \LogicException(
                    'Deleting a ClickHouse row by ID requires a string table name.'
                );
            }

            $this->where($this->from.'.id', '=', $id);
        }

        $lightweight ??= $this->usesLightweightDeletesByDefault();
        $this->validateDeletePartition($partition);

        if ($partition !== null && ! $partition instanceof Expression) {
            $this->addBinding($partition, 'partition');
        }

        try {
            $this->applyBeforeQueryCallbacks();
            $grammar = $this->grammar;
            $connection = $this->clickHouseConnection();
            /** @var array<array-key, mixed> $bindings */
            $bindings = $this->bindings;

            if (! $grammar instanceof ClickHouseQueryGrammar) {
                throw new \LogicException('ClickHouse deletes require ClickHouseQueryGrammar.');
            }

            return $connection->delete(
                $grammar->compileDelete($this, $lightweight, $partition),
                $this->cleanBindings(
                    $grammar->prepareBindingsForDelete($bindings),
                ),
            );
        } finally {
            $this->setBindings([], 'partition');
        }
    }

    public function deleteLightweight(mixed $partition = null): int
    {
        return $this->delete(lightweight: true, partition: $partition);
    }

    public function deleteMutation(mixed $partition = null): int
    {
        return $this->delete(lightweight: false, partition: $partition);
    }

    private function usesLightweightDeletesByDefault(): bool
    {
        $configured = filter_var(
            $this->clickHouseConnection()->getConfig('use_lightweight_delete') ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        if ($configured === null) {
            throw new InvalidArgumentException(
                'ClickHouse use_lightweight_delete must be a boolean.'
            );
        }

        return $configured;
    }

    private function clickHouseConnection(): ClickHouseConnection
    {
        if (! $this->connection instanceof ClickHouseConnection) {
            throw new \LogicException(
                'ClickHouse queries require a ClickHouse connection.'
            );
        }

        return $this->connection;
    }

    private function validateDeletePartition(mixed $partition): void
    {
        if (
            $partition !== null
            && ! is_scalar($partition)
            && ! $partition instanceof DateTimeInterface
            && ! $partition instanceof Expression
            && ! $partition instanceof UnitEnum
        ) {
            throw new InvalidArgumentException(
                'ClickHouse delete partitions must be scalar values, dates, enums, or expressions.'
            );
        }
    }
}
