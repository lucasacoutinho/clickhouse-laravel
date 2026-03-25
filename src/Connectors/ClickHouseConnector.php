<?php

namespace ClickHouse\Laravel\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

class ClickHouseConnector extends Connector implements ConnectorInterface
{
    protected function getDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 9000;
        $database = $config['database'] ?? 'default';

        $dsn = "clickhouse:host={$host};port={$port};dbname={$database}";

        if (isset($config['compression'])) {
            $dsn .= ';compression=' . $config['compression'];
        }

        return $dsn;
    }

    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);

        // ClickHouse PDO driver supports ERRMODE and ATTR_TIMEOUT.
        // Compression is passed via DSN parameter.
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if (isset($config['timeout'])) {
            $options[PDO::ATTR_TIMEOUT] = (int) $config['timeout'];
        }

        return new PDO(
            $dsn,
            $config['username'] ?? 'default',
            $config['password'] ?? '',
            $options,
        );
    }
}
