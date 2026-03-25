<?php

namespace ClickHouse\Laravel\Query\Concerns;

use Illuminate\Database\Query\Builder;

trait HasClickHouseJoin
{
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
    public function clickhouseJoin(
        string|Builder $table,
        string|array|null $using = null,
        string $strict = 'ALL',
        string $type = 'LEFT',
        bool $global = false,
        ?string $alias = null,
        ?array $on = null,
    ): static {
        $join = [
            'strict' => strtoupper($strict),
            'type'   => strtoupper($type),
            'global' => $global,
            'alias'  => $alias,
        ];

        if ($table instanceof Builder) {
            $join['subquery'] = $table;
            $join['table'] = null;
        } else {
            $join['subquery'] = null;
            $join['table'] = $table;
        }

        if ($on !== null) {
            $join['on'] = $on;
            $join['using'] = null;
        } elseif ($using !== null) {
            $join['using'] = is_array($using) ? $using : [$using];
            $join['on'] = null;
        } else {
            $join['using'] = null;
            $join['on'] = null;
        }

        $this->clickhouseJoins[] = $join;

        return $this;
    }

    public function anyLeftJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ANY', 'LEFT', $global, $alias, $on);
    }

    public function allLeftJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ALL', 'LEFT', $global, $alias, $on);
    }

    public function anyInnerJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ANY', 'INNER', $global, $alias, $on);
    }

    public function allInnerJoin(string|Builder $table, string|array|null $using = null, bool $global = false, ?string $alias = null, ?array $on = null): static
    {
        return $this->clickhouseJoin($table, $using, 'ALL', 'INNER', $global, $alias, $on);
    }
}
