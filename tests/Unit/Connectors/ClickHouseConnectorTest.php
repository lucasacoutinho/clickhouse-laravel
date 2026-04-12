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
        $connector = new ClickHouseConnector();
        $method = new \ReflectionMethod($connector, 'getDsn');

        return $method->invoke($connector, $config);
    }

    public function testDsnWithDefaults(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default', $dsn);
    }

    public function testDsnWithCustomConfig(): void
    {
        $dsn = $this->getDsn([
            'host' => '10.0.0.1',
            'port' => 9440,
            'database' => 'analytics',
        ]);

        $this->assertSame('clickhouse:host=10.0.0.1;port=9440;dbname=analytics', $dsn);
    }

    public function testDsnPartialConfig(): void
    {
        $dsn = $this->getDsn(['database' => 'mydb']);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=mydb', $dsn);
    }

    public function testDsnWithCompressionLz4(): void
    {
        $dsn = $this->getDsn(['compression' => 'lz4']);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default;compression=lz4', $dsn);
    }

    public function testDsnWithCompressionZstd(): void
    {
        $dsn = $this->getDsn(['compression' => 'zstd']);
        $this->assertStringContainsString('compression=zstd', $dsn);
    }

    public function testDsnWithoutCompressionHasNoParam(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertStringNotContainsString('compression', $dsn);
    }

    public function testDsnWithSsl(): void
    {
        $dsn = $this->getDsn(['ssl' => true]);
        $this->assertSame('clickhouse:host=localhost;port=9000;dbname=default;ssl=true', $dsn);
    }

    public function testDsnWithSslAndSkipVerify(): void
    {
        $dsn = $this->getDsn(['ssl' => true, 'ssl_skip_verify' => true]);
        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('skip_verify=true', $dsn);
    }

    public function testDsnWithSslAndCaPath(): void
    {
        $dsn = $this->getDsn(['ssl' => true, 'ssl_ca_path' => '/etc/ssl/certs']);
        $this->assertStringContainsString('ssl=true', $dsn);
        $this->assertStringContainsString('ca_path=/etc/ssl/certs', $dsn);
    }

    public function testDsnWithSslFullConfig(): void
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

    public function testDsnWithoutSslHasNoParam(): void
    {
        $dsn = $this->getDsn([]);
        $this->assertStringNotContainsString('ssl', $dsn);
    }
}
