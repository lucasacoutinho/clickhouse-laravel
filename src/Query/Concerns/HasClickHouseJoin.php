<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Exceptions\ClickHouseGrammarException;
use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;

trait HasClickHouseJoin
{
    /**
     * @var list<array{
     *     strict: string,
     *     type: string,
     *     global: bool,
     *     alias: string|null,
     *     using: list<string>|null,
     *     on: list<array{0: Expression|string, 1: string, 2: Expression|string}>|null,
     *     subquery: string|null,
     *     table: string|null
     * }>
     */
    public array $clickhouseJoins = [];

    /**
     * ClickHouse-specific JOIN with strictness (ANY/ALL) and distribution (GLOBAL).
     * Supports both USING and ON conditions.
     *
     * Usage:
     *   ->clickhouseJoin('users', ['user_id'], 'ANY', 'LEFT')
     *   ->clickhouseJoin('dim', ['key'], 'ALL', 'INNER', global: true)
     *   ->clickhouseJoin('users', on: [['orders.user_id', '=', 'users.id']])
     */
    /**
     * @param  array<array-key, mixed>|string|null  $using
     * @param  array<array-key, mixed>|null  $on
     */
    public function clickhouseJoin(
        string|Builder $table,
        string|array|null $using = null,
        string $strict = 'ALL',
        string $type = 'LEFT',
        bool $global = false,
        ?string $alias = null,
        ?array $on = null,
    ): static {
        $strict = ClickHouseSql::oneOf($strict, ['ANY', 'ALL', 'ASOF'], 'JOIN strictness');
        $type = ClickHouseSql::oneOf($type, ['LEFT', 'INNER', 'RIGHT', 'FULL', 'CROSS'], 'JOIN type');

        if ($using !== null && $on !== null) {
            throw ClickHouseGrammarException::ambiguousJoinKeys();
        }

        if ($strict === 'ASOF' && ! in_array($type, ['LEFT', 'INNER'], true)) {
            throw new \InvalidArgumentException(
                'ClickHouse ASOF JOIN supports only LEFT and INNER join types.'
            );
        }

        if ($type === 'CROSS' && ($using !== null || $on !== null)) {
            throw new \InvalidArgumentException('ClickHouse CROSS JOIN cannot define USING or ON keys.');
        }

        if ($alias !== null && trim($alias) === '') {
            throw new \InvalidArgumentException(
                'ClickHouse JOIN alias must be a non-empty string.'
            );
        }

        if (is_string($table) && trim($table) === '') {
            throw new \InvalidArgumentException(
                'ClickHouse JOIN table must be a non-empty string.'
            );
        }

        /** @var list<string>|null $normalizedUsing */
        $normalizedUsing = null;
        /** @var list<array{0: Expression|string, 1: string, 2: Expression|string}>|null $normalizedOn */
        $normalizedOn = null;

        if ($on !== null) {
            if ($on === []) {
                throw ClickHouseGrammarException::missingJoinKeys();
            }

            $normalizedOn = [];
            foreach ($on as $condition) {
                if (! is_array($condition) || count($condition) !== 3) {
                    throw new \InvalidArgumentException(
                        'Each ClickHouse JOIN ON condition must contain [left, operator, right].'
                    );
                }

                $condition = array_values($condition);
                $left = $condition[0] ?? null;
                $operator = $condition[1] ?? null;
                $right = $condition[2] ?? null;

                if (! is_string($operator)) {
                    throw new \InvalidArgumentException(
                        'ClickHouse JOIN operators must be strings.'
                    );
                }

                if (
                    ! $left instanceof Expression
                    && (! is_string($left) || trim($left) === '')
                ) {
                    throw new \InvalidArgumentException(
                        'ClickHouse JOIN ON left operands must be non-empty identifiers or expressions.'
                    );
                }

                if (
                    ! $right instanceof Expression
                    && (! is_string($right) || trim($right) === '')
                ) {
                    throw new \InvalidArgumentException(
                        'ClickHouse JOIN ON right operands must be non-empty identifiers or expressions.'
                    );
                }

                $normalizedOn[] = [
                    $left,
                    ClickHouseSql::oneOf(
                        $operator,
                        ['=', '!=', '<>', '<', '<=', '>', '>='],
                        'JOIN operator',
                    ),
                    $right,
                ];
            }
        } elseif ($using !== null) {
            $usingColumns = is_array($using) ? array_values($using) : [$using];

            if ($usingColumns === []) {
                throw ClickHouseGrammarException::missingJoinKeys();
            }

            $normalizedUsing = [];

            foreach ($usingColumns as $column) {
                if (! is_string($column) || trim($column) === '') {
                    throw new \InvalidArgumentException('ClickHouse JOIN USING columns must be non-empty strings.');
                }

                $normalizedUsing[] = $column;
            }
        }

        if ($type !== 'CROSS' && $normalizedUsing === null && $normalizedOn === null) {
            throw ClickHouseGrammarException::missingJoinKeys();
        }

        $subquery = null;
        $tableName = null;
        /** @var list<mixed> $bindings */
        $bindings = [];

        if ($table instanceof Builder) {
            if ($alias === null) {
                throw ClickHouseGrammarException::missingSubqueryAlias();
            }

            [$sql, $bindings] = $this->createSub($table);
            if (! is_string($sql)) {
                throw new \UnexpectedValueException(
                    'ClickHouse JOIN subqueries must compile to SQL strings.'
                );
            }

            $subquery = $sql;
        } else {
            $tableName = $table;
        }

        $this->clickhouseJoins[] = [
            'strict' => $strict,
            'type' => $type,
            'global' => $global,
            'alias' => $alias,
            'using' => $normalizedUsing,
            'on' => $normalizedOn,
            'subquery' => $subquery,
            'table' => $tableName,
        ];
        $this->addBinding($bindings, 'clickhouseJoin');

        return $this;
    }

    /**
     * @param  array<array-key, mixed>|string|null  $using
     * @param  array<array-key, mixed>|null  $on
     */
    public function anyLeftJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ANY', 'LEFT', $global, $alias, $on);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $using
     * @param  array<array-key, mixed>|null  $on
     */
    public function allLeftJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ALL', 'LEFT', $global, $alias, $on);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $using
     * @param  array<array-key, mixed>|null  $on
     */
    public function anyInnerJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ANY', 'INNER', $global, $alias, $on);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $using
     * @param  array<array-key, mixed>|null  $on
     */
    public function allInnerJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ALL', 'INNER', $global, $alias, $on);
    }
}
