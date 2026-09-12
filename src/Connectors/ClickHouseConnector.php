<?php

namespace ClickHouse\Laravel\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** @api */
class ClickHouseConnector extends Connector implements ConnectorInterface
{
    /**
     * @param  array<array-key, mixed>  $config
     *
     * @psalm-suppress MixedAssignment Configuration values are validated before use.
     */
    protected function getDsn(array $config): string
    {
        $host = $this->dsnValue($config['host'] ?? 'localhost', 'host');
        $port = $this->integer($config['port'] ?? 9000, 'port', 1, 65535);
        $database = $this->dsnValue($config['database'] ?? 'default', 'database');

        $dsn = "clickhouse:host={$host};port={$port};dbname={$database}";

        $compressionValue = $config['compression'] ?? null;
        if (filled($compressionValue)) {
            $compression = strtolower($this->dsnValue($compressionValue, 'compression'));

            if (! in_array($compression, ['none', 'lz4', 'zstd'], true)) {
                throw new InvalidArgumentException(
                    'ClickHouse compression must be one of: none, lz4, zstd.'
                );
            }

            $dsn .= ';compression='.$compression;
        }

        foreach (['max_buffered_rows', 'max_buffered_bytes'] as $option) {
            if (array_key_exists($option, $config)) {
                $value = $this->integer($config[$option], $option, 1, PHP_INT_MAX);
                $dsn .= ';'.$option.'='.$value;
            }
        }

        $tlsOptions = [
            'ca_path' => $config['ssl_ca_path'] ?? $config['ca_path'] ?? null,
            'ca_file' => $config['ssl_ca_file'] ?? $config['ca_file'] ?? null,
            'client_cert' => $config['ssl_client_cert'] ?? $config['client_cert'] ?? null,
            'client_key' => $config['ssl_client_key'] ?? $config['client_key'] ?? null,
        ];
        $skipVerify = $this->boolean(
            $config['ssl_skip_verify'] ?? $config['skip_verify'] ?? false,
            'ssl_skip_verify',
        );
        $ssl = $this->boolean($config['ssl'] ?? false, 'ssl') || $skipVerify;
        foreach ($tlsOptions as $value) {
            $ssl = $ssl || filled($value);
        }

        if (filled($tlsOptions['client_cert']) !== filled($tlsOptions['client_key'])) {
            throw new InvalidArgumentException(
                'ClickHouse TLS client certificate and client key must be configured together.'
            );
        }

        if ($ssl) {
            $dsn .= ';ssl=true';

            if ($skipVerify) {
                $dsn .= ';skip_verify=true';
            }

            foreach ($tlsOptions as $name => $value) {
                if (filled($value)) {
                    $dsn .= ';'.$name.'='.$this->dsnValue($value, "TLS {$name}");
                }
            }
        }

        return $dsn;
    }

    /** @param array<array-key, mixed> $config */
    public function connect(array $config): PDO
    {
        if (isset($config['cluster'])) {
            if (! is_array($config['cluster']) || $config['cluster'] === []) {
                throw new InvalidArgumentException('ClickHouse cluster must contain at least one node.');
            }

            $nodes = $config['cluster'];
            unset($config['cluster']);
            /** @var list<Throwable> $failures */
            $failures = [];

            foreach ($nodes as $node) {
                if (! is_array($node) || blank($node['host'] ?? null)) {
                    throw new InvalidArgumentException(
                        'Each ClickHouse cluster node must define a non-empty host.'
                    );
                }

                try {
                    $nodeConfig = array_merge($config, $node);
                    unset($nodeConfig['cluster']);

                    return $this->connect($nodeConfig);
                } catch (InvalidArgumentException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $failures[] = $exception;
                }
            }

            throw new RuntimeException(
                'All ClickHouse cluster nodes are unreachable.',
                0,
                $failures[array_key_last($failures)] ?? null,
            );
        }

        $dsn = $this->getDsn($config);

        $configuredOptions = $config['options'] ?? [];
        if (! is_array($configuredOptions)) {
            throw new InvalidArgumentException('ClickHouse PDO options must be an array.');
        }

        $options = array_filter(
            $configuredOptions,
            fn ($key) => is_int($key),
            ARRAY_FILTER_USE_KEY,
        );
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        if (isset($config['timeout'])) {
            $options[PDO::ATTR_TIMEOUT] = $this->integer(
                $config['timeout'],
                'timeout',
                0,
                PHP_INT_MAX,
            );
        }

        /* Persistent connections reuse the underlying ClickHouse native
         * TCP handshake across PHP-FPM requests, which can be a sizable
         * wall-time win on short API responses that would otherwise
         * repay the auth / database-selection handshake for every
         * request. Opt-in via the 'persistent' connection flag so
         * operators who prefer per-request isolation can stay on the
         * default behavior. */
        if ($this->boolean($config['persistent'] ?? false, 'persistent')) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $username = $this->credential($config['username'] ?? 'default', 'username');
        $password = $this->credential($config['password'] ?? '', 'password');

        return new PDO(
            $dsn,
            $username,
            $password,
            $options,
        );
    }

    private function dsnValue(mixed $value, string $label): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("ClickHouse {$label} must be a string.");
        }

        $value = (string) $value;

        if (
            $value === ''
            || str_contains($value, ';')
            || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException(
                "Invalid ClickHouse {$label}: empty values, semicolons, and control characters are not allowed."
            );
        }

        return $value;
    }

    private function integer(mixed $value, string $label, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || $validated < $minimum || $validated > $maximum) {
            throw new InvalidArgumentException(
                "ClickHouse {$label} must be an integer between {$minimum} and {$maximum}."
            );
        }

        return $validated;
    }

    private function boolean(mixed $value, string $label): bool
    {
        $validated = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($validated === null) {
            throw new InvalidArgumentException("ClickHouse {$label} must be a boolean.");
        }

        return $validated;
    }

    private function credential(mixed $value, string $label): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException(
                "ClickHouse {$label} must be a string or null."
            );
        }

        return $value;
    }
}
