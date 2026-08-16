<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Expression as QueryExpression;

/**
 * WITH FILL fills gaps in time series and ordered sequences.
 *
 * Three ways to use:
 *   ->withFill(from: DB::raw("toDateTime64('...', 3)"), step: DB::raw("toIntervalMinute(5)"))
 *   ->withFillRaw("FROM toDateTime64('...', 3) STEP toIntervalMinute(5)")
 *   ->withFillTime($start, $end, '5 minute', precision: 3)
 */
trait HasWithFill
{
    /**
     * @var array<int, array{raw: string}|array{from?: string, to?: string, step?: string}>
     */
    public array $withFills = [];

    /** @var list<array{raw: string}|array{column: string}> */
    public array $interpolateColumns = [];

    /**
     * Attach WITH FILL to the most recent orderBy column.
     * Accepts Expression objects or numeric literals for each parameter.
     * Use DB::raw() when a ClickHouse expression is required.
     *
     * Usage:
     *   ->orderBy('bucket')
     *   ->withFill(from: DB::raw("toDateTime64('2026-01-01', 3)"), step: DB::raw("toIntervalMinute(5)"))
     */
    public function withFill(
        Expression|int|float|null $from = null,
        Expression|int|float|null $to = null,
        Expression|int|float|null $step = null,
    ): static {
        $lastIndex = count($this->orders ?? []) - 1;

        if ($lastIndex < 0) {
            return $this;
        }

        /** @var array{from?: string, to?: string, step?: string} $params */
        $params = [];

        foreach (['from' => $from, 'to' => $to, 'step' => $step] as $name => $value) {
            $compiled = $this->compileFillValue($value);
            if ($compiled !== null) {
                $params[$name] = $compiled;
            }
        }

        $this->withFills[$lastIndex] = $params;

        return $this;
    }

    /**
     * Attach WITH FILL using a raw SQL expression.
     *
     * Usage:
     *   ->orderBy('bucket')
     *   ->withFillRaw("FROM toDateTime64('2026-01-01', 3) STEP toIntervalMinute(5)")
     */
    public function withFillRaw(string $expression): static
    {
        $lastIndex = count($this->orders ?? []) - 1;

        if ($lastIndex < 0) {
            return $this;
        }

        $this->withFills[$lastIndex] = [
            'raw' => ClickHouseSql::safeExpression($expression, 'WITH FILL expression'),
        ];

        return $this;
    }

    /**
     * Convenience for time series gap filling with DateTime64.
     *
     * Usage:
     *   ->orderBy('bucket')
     *   ->withFillTime('2026-01-01', '2026-01-02', '5 minute')
     *   ->withFillTime($start, $end, '100 millisecond', precision: 3)
     *
     * @param  string  $from  Start datetime string
     * @param  string  $to  End datetime string
     * @param  string  $step  Interval expression (e.g. '5 minute', '100 millisecond', '1 hour')
     * @param  int  $precision  DateTime64 precision (0=seconds, 3=ms, 6=us, 9=ns)
     *
     * @psalm-suppress PossiblyUndefinedIntArrayOffset preg_match defines both capture groups.
     */
    public function withFillTime(string $from, string $to, string $step, int $precision = 0): static
    {
        if ($precision < 0 || $precision > 9) {
            throw new \InvalidArgumentException('ClickHouse DateTime64 precision must be between 0 and 9.');
        }

        if (! preg_match(
            '/\A([1-9][0-9]*)\s+(nanosecond|microsecond|millisecond|second|minute|hour|day|week|month|quarter|year)s?\z/i',
            trim($step),
            $matches,
        )) {
            throw new \InvalidArgumentException(
                'ClickHouse WITH FILL step must look like "5 minute" using a positive integer and supported unit.'
            );
        }

        $amount = $matches[1];
        $unit = ucfirst(strtolower($matches[2]));
        $quotedFrom = ClickHouseSql::quoteString($from);
        $quotedTo = ClickHouseSql::quoteString($to);

        $dtFunc = $precision > 0
            ? "toDateTime64({$quotedFrom}, {$precision})"
            : "toDateTime({$quotedFrom})";
        $dtFuncTo = $precision > 0
            ? "toDateTime64({$quotedTo}, {$precision})"
            : "toDateTime({$quotedTo})";
        $intervalFunc = "toInterval{$unit}({$amount})";

        return $this->withFill(
            from: $this->trustedFillExpression($dtFunc),
            to: $this->trustedFillExpression($dtFuncTo),
            step: $this->trustedFillExpression($intervalFunc),
        );
    }

    /**
     * INTERPOLATE specifies how to compute values for filled-in rows.
     *
     * Usage:
     *   ->interpolate('cumulative')                    // INTERPOLATE (cumulative)
     *   ->interpolate('cumulative', DB::raw('value AS 0'))
     */
    /**
     * @param  array<array-key, mixed>|Expression|string  ...$columns
     *
     * @psalm-suppress MixedAssignment Nested entries are validated in the loop.
     */
    public function interpolate(string|Expression|array ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $nestedColumn) {
                    if (! is_string($nestedColumn) && ! $nestedColumn instanceof Expression) {
                        throw new \InvalidArgumentException(
                            'ClickHouse INTERPOLATE columns must be strings or expressions.'
                        );
                    }

                    $this->interpolate($nestedColumn);
                }

                continue;
            }

            if ($col instanceof Expression) {
                $raw = $col->getValue($this->getGrammar());
                if (! is_string($raw)) {
                    throw new \InvalidArgumentException(
                        'ClickHouse INTERPOLATE expressions must compile to strings.'
                    );
                }

                $this->interpolateColumns[] = [
                    'raw' => $raw,
                ];

                continue;
            }

            ClickHouseSql::nonEmpty($col, 'INTERPOLATE column');
            $this->interpolateColumns[] = ['column' => $col];
        }

        return $this;
    }

    private function compileFillValue(Expression|int|float|null $value): ?string
    {
        if ($value instanceof Expression) {
            $expression = $value->getValue($this->getGrammar());

            if (is_int($expression) || is_float($expression)) {
                return ClickHouseSql::literal($expression, 'WITH FILL value');
            }

            return $expression;
        }

        return $value === null ? null : ClickHouseSql::literal($value, 'WITH FILL value');
    }

    /** @return QueryExpression<literal-string> */
    private function trustedFillExpression(string $expression): QueryExpression
    {
        /** @var literal-string $expression */
        return new QueryExpression($expression);
    }
}
