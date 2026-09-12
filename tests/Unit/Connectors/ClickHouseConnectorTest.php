<?php

namespace ClickHouse\Laravel\Tests\Unit\Connectors;

use ClickHouse\Laravel\Connectors\ClickHouseConnector;
use ClickHouse\Laravel\Tests\TestCase;

class ClickHouseConnectorTest extends TestCase
{
    /**
     * Access the protected getDsn method via reflection.
     */
    protected function getDsn(array $config): string
    {
        $connector = new ClickHouseConnector;
        $method = new \ReflectionMethod($connector, 'getDsn');

        return $method->invoke($connector, $config);
    }

    public function test_dsn_with_defaults(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default', $dsn);
    }

    public function test_dsn_with_custom_config(): void
    {
        $dsn = $this->getDsn([
            'host' => '10.0.0.1',
            'port' => 9440,
            'database' => 'analytics',
        ]);

        $this->assertSame('clickhouse:host=10.0.0.1;port=9440;dbname=analytics', $dsn);
    }

    public function test_dsn_partial_config(): void
    {
        $dsn = $this->getDsn(['database' => 'mydb']);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=mydb', $dsn);
    }

    public function test_dsn_with_compression_lz4(): void
    {
        $dsn = $this->getDsn(['compression' => 'lz4']);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default;compression=lz4', $dsn);
    }

    public function test_dsn_with_compression_zstd(): void
    {
        $dsn = $this->getDsn(['compression' => 'zstd']);
        $this->assertStringContainsString('compression=zstd', $dsn);
    }

    public function test_dsn_without_compression_has_no_param(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertStringNotContainsString('compression', $dsn);
    }

    public function test_dsn_without_buffer_limits_delegates_to_driver_defaults(): void
    {
        foreach ([[], ['max_buffered_rows' => null, 'max_buffered_bytes' => null]] as $config) {
            $dsn = $this->getDsn($config);

            $this->assertStringNotContainsString('max_buffered_rows', $dsn);
            $this->assertStringNotContainsString('max_buffered_bytes', $dsn);
        }
    }

    public function test_dsn_with_buffer_limits(): void
    {
        $dsn = $this->getDsn([
            'max_buffered_rows' => '10000',
            'max_buffered_bytes' => '67108864',
        ]);

        $this->assertSame(
            'clickhouse:host=localhost;port=9000;dbname=default;max_buffered_rows=10000;max_buffered_bytes=67108864',
            $dsn,
        );
    }

    public function test_invalid_buffer_limits_are_rejected(): void
    {
        foreach (['max_buffered_rows', 'max_buffered_bytes'] as $option) {
            foreach ([0, -1, 'not-a-number'] as $value) {
                try {
                    $this->getDsn([$option => $value]);
                    $this->fail("{$option} accepted invalid value.");
                } catch (\InvalidArgumentException $exception) {
                    $this->assertStringContainsString($option, $exception->getMessage());
                }
            }
        }
    }

    public function test_blank_compression_is_treated_as_unconfigured(): void
    {
        $dsn = $this->getDsn(['compression' => '   ']);

        $this->assertStringNotContainsString('compression', $dsn);
    }

    public function test_dsn_with_ssl(): void
    {
        $dsn = $this->getDsn(['ssl' => true]);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default;ssl=true', $dsn);
    }

    public function test_dsn_with_ssl_and_skip_verify(): void
    {
        $dsn = $this->getDsn(['ssl' => true, 'ssl_skip_verify' => true]);
        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('skip_verify=true', $dsn);
    }

    public function test_skip_verify_enables_ssl_automatically(): void
    {
        $dsn = $this->getDsn(['ssl_skip_verify' => true]);

        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('skip_verify=true', $dsn);
    }

    public function test_dsn_with_ssl_and_ca_path(): void
    {
        $dsn = $this->getDsn(['ssl' => true, 'ssl_ca_path' => '/etc/ssl/certs']);
        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('ca_path=/etc/ssl/certs', $dsn);
    }

    public function test_dsn_with_ssl_full_config(): void
    {
        $dsn = $this->getDsn([
            'host' => 'abc123.clickhouse.cloud',
            'port' => 9440,
            'database' => 'default',
            'ssl' => true,
        ]);

        $this->assertSame(
            'clickhouse:host=abc123.clickhouse.cloud;port=9440;dbname=default;ssl=true',
            $dsn,
        );
    }

    public function test_dsn_with_ca_file_and_mutual_tls(): void
    {
        $dsn = $this->getDsn([
            'ssl' => true,
            'ssl_ca_file' => '/run/secrets/ca.pem',
            'ssl_client_cert' => '/run/secrets/client.crt',
            'ssl_client_key' => '/run/secrets/client.key',
        ]);

        $this->assertStringContainsString('ca_file=/run/secrets/ca.pem', $dsn);
        $this->assertStringContainsString('client_cert=/run/secrets/client.crt', $dsn);
        $this->assertStringContainsString('client_key=/run/secrets/client.key', $dsn);
    }

    public function test_tls_material_enables_ssl_automatically(): void
    {
        $dsn = $this->getDsn(['ssl_ca_file' => '/run/secrets/ca.pem']);

        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('ca_file=/run/secrets/ca.pem', $dsn);
    }

    public function test_blank_tls_material_does_not_enable_ssl(): void
    {
        $dsn = $this->getDsn([
            'ssl_ca_file' => '   ',
            'ssl_client_cert' => '',
            'ssl_client_key' => '',
        ]);

        $this->assertStringNotContainsString('ssl', $dsn);
    }

    public function test_client_certificate_requires_matching_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['ssl_client_cert' => '/run/secrets/client.crt']);
    }

    public function test_invalid_port_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['port' => 70000]);
    }

    public function test_negative_port_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['port' => -1]);
    }

    public function test_invalid_compression_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['compression' => 'gzip']);
    }

    public function test_dsn_delimiter_injection_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['host' => 'localhost;ssl=true']);
    }

    public function test_dsn_control_character_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getDsn(['database' => "analytics\nssl=true"]);
    }

    public function test_invalid_cluster_node_config_is_not_hidden_by_failover(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClickHouseConnector)->connect([
            'cluster' => [
                ['host' => 'node-one', 'port' => -1],
                ['host' => 'node-two', 'port' => 9000],
            ],
        ]);
    }

    public function test_cluster_nodes_inherit_buffer_limits_from_base_config(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $connector = new class($pdo) extends ClickHouseConnector
        {
            /** @var array<string, mixed>|null */
            public ?array $leafConfig = null;

            public function __construct(private \PDO $pdo) {}

            public function connect(array $config): \PDO
            {
                if (isset($config['cluster'])) {
                    return parent::connect($config);
                }

                $this->leafConfig = $config;

                return $this->pdo;
            }
        };

        $connector->connect([
            'max_buffered_rows' => 10000,
            'max_buffered_bytes' => 67108864,
            'cluster' => [
                ['host' => 'node-one', 'port' => 9000],
            ],
        ]);

        $this->assertNotNull($connector->leafConfig);
        $this->assertSame(10000, $connector->leafConfig['max_buffered_rows']);
        $this->assertSame(67108864, $connector->leafConfig['max_buffered_bytes']);
        $this->assertSame(
            'clickhouse:host=node-one;port=9000;dbname=default;max_buffered_rows=10000;max_buffered_bytes=67108864',
            $this->getDsn($connector->leafConfig),
        );
    }

    public function test_dsn_without_ssl_has_no_param(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertStringNotContainsString('ssl', $dsn);
    }
}
