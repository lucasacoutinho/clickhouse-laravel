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
}
