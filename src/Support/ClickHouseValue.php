<?php

namespace ClickHouse\Laravel\Support;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Stringable;
use UnitEnum;

final class ClickHouseValue
{
    /** @param array<array-key, mixed> $values */
    public static function array(array $values): string
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException(
                'ClickHouse Array values must use sequential integer keys; use ClickHouseValue::map() for maps.'
            );
        }

        return '['.implode(', ', array_map(self::literal(...), $values)).']';
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @psalm-suppress MixedAssignment Map values are encoded by literal().
     */
    public static function map(array $values): string
    {
        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs[] = self::literal($key).': '.self::literal($value);
        }

        return '{'.implode(', ', $pairs).'}';
    }

    public static function tuple(mixed ...$values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException(
                'ClickHouse Tuple values require at least one element.'
            );
        }

        return '('.implode(', ', array_map(self::literal(...), $values)).')';
    }

    /**
     * Encode a PHP value for a native ClickHouse JSON column.
     *
     * @throws JsonException
     */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            is_array($value) && array_is_list($value) => self::array($value),
            is_array($value) => self::map($value),
            $value instanceof DateTimeInterface => ClickHouseSql::quoteString(
                $value->format('Y-m-d H:i:s.uP')
            ),
            $value instanceof BackedEnum => self::literal($value->value),
            $value instanceof UnitEnum => ClickHouseSql::quoteString($value->name),
            $value instanceof Stringable => ClickHouseSql::quoteString((string) $value),
            is_scalar($value) || $value === null => ClickHouseSql::literal($value),
            default => throw new InvalidArgumentException(
                'Unsupported value in ClickHouse Array, Map, or Tuple literal.'
            ),
        };
    }
}
