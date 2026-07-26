<?php

namespace ClickHouse\Laravel\Support;

use InvalidArgumentException;

final class ClickHouseSql
{
    public static function token(string $value, string $label): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value)) {
            throw new InvalidArgumentException("Invalid ClickHouse {$label}: {$value}");
        }

        return $value;
    }

    public static function settingName(string $value): string
    {
        return self::token($value, 'setting name');
    }

    public static function quoteIdentifier(string $value, string $label = 'identifier'): string
    {
        self::nonEmpty($value, $label);

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Invalid ClickHouse {$label}: control characters are not allowed.");
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    public static function quoteString(string $value): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static function (array $match): string {
                /** @psalm-suppress PossiblyUndefinedIntArrayOffset preg_replace supplies the full match. */
                $character = $match[0];

                return '\\x'.strtoupper(bin2hex($character));
            },
            $escaped,
        );

        if ($escaped === null) {
            throw new InvalidArgumentException('Could not escape a ClickHouse string.');
        }

        return "'".$escaped."'";
    }

    public static function literal(mixed $value, string $label = 'value'): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => self::float($value),
            is_float($value) => throw new InvalidArgumentException(
                "Invalid ClickHouse {$label}: non-finite numbers are not supported."
            ),
            is_string($value) => self::quoteString($value),
            default => throw new InvalidArgumentException(
                "Invalid ClickHouse {$label}: expected null, bool, int, float, or string."
            ),
        };
    }

    /** @param list<string> $allowed */
    public static function oneOf(string $value, array $allowed, string $label): string
    {
        $normalized = strtoupper($value);

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ClickHouse %s: %s. Expected one of: %s.',
                $label,
                $value,
                implode(', ', $allowed),
            ));
        }

        return $normalized;
    }

    public static function safeExpression(string $value, string $label): string
    {
        self::nonEmpty($value, $label);

        if (
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || str_contains($value, ';')
            || str_contains($value, '--')
            || str_contains($value, '#')
            || str_contains($value, '/*')
            || str_contains($value, '*/')
        ) {
            throw new InvalidArgumentException(
                "Invalid ClickHouse {$label}: multiple statements and SQL comments are not allowed."
            );
        }

        return $value;
    }

    public static function nonEmpty(string $value, string $label): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("ClickHouse {$label} cannot be empty.");
        }

        return $value;
    }

    private static function float(float $value): string
    {
        $formatted = json_encode(
            $value,
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return str_ireplace('e+', 'e', $formatted);
    }
}
