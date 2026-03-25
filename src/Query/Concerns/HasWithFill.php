<?php

namespace ClickHouse\Laravel\Query\Concerns;

use Illuminate\Contracts\Database\Query\Expression;

/**
 * WITH FILL — fill gaps in time series and ordered sequences.
 *
 * Three ways to use:
 *   ->withFill(from: DB::raw("toDateTime64('...', 3)"), step: DB::raw("toIntervalMinute(5)"))
 *   ->withFillRaw("FROM toDateTime64('...', 3) STEP toIntervalMinute(5)")
 *   ->withFillTime($start, $end, '5 minute', precision: 3)
 */
trait HasWithFill
{
    public array $withFills = [];
    public array $interpolateColumns = [];

    /**
     * Attach WITH FILL to the most recent orderBy column.
     * Accepts Expression objects or raw strings for each parameter.
     *
     * Usage:
     *   ->orderBy('bucket')
     *   ->withFill(from: DB::raw("toDateTime64('2026-01-01', 3)"), step: DB::raw("toIntervalMinute(5)"))
     */
    public function withFill(
        Expression|string|null $from = null,
        Expression|string|null $to = null,
        Expression|string|null $step = null,
    ): static {
        $lastIndex = count($this->orders ?? []) - 1;

        if ($lastIndex < 0) {
            return $this;
        }

        $params = array_filter(
            [
                'from' => $from instanceof Expression ? $from->getValue($this->getGrammar()) : $from,
                'to'   => $to instanceof Expression ? $to->getValue($this->getGrammar()) : $to,
                'step' => $step instanceof Expression ? $step->getValue($this->getGrammar()) : $step,
            ],
            fn($v) => $v !== null,
        );

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

        $this->withFills[$lastIndex] = ['raw' => $expression];

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
     * @param string      $from      Start datetime string
     * @param string      $to        End datetime string
     * @param string      $step      Interval expression (e.g. '5 minute', '100 millisecond', '1 hour')
     * @param int         $precision DateTime64 precision (0=seconds, 3=ms, 6=us, 9=ns)
     */
    public function withFillTime(string $from, string $to, string $step, int $precision = 0): static
    {
        $parts = preg_split('/\s+/', trim($step), 2);
        $amount = $parts[0];
        $unit = ucfirst(strtolower($parts[1] ?? 'second'));

        $dtFunc = $precision > 0 ? "toDateTime64('{$from}', {$precision})" : "toDateTime('{$from}')";
        $dtFuncTo = $precision > 0 ? "toDateTime64('{$to}', {$precision})" : "toDateTime('{$to}')";
        $intervalFunc = "toInterval{$unit}({$amount})";

        return $this->withFill(from: $dtFunc, to: $dtFuncTo, step: $intervalFunc);
    }

    /**
     * INTERPOLATE — specify how to compute values for filled-in rows.
     *
     * Usage:
     *   ->interpolate('cumulative')                    // INTERPOLATE (cumulative)
     *   ->interpolate('cumulative', 'value AS 0')      // INTERPOLATE (cumulative, value AS 0)
     */
    public function interpolate(string|array ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                $this->interpolateColumns = array_merge($this->interpolateColumns, $col);
            } else {
                $this->interpolateColumns[] = $col;
            }
        }

        return $this;
    }
}
