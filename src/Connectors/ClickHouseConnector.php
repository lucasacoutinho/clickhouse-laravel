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

        if (!empty($config['ssl'])) {
            $dsn .= ';ssl=true';
            if (!empty($config['ssl_skip_verify'])) {
                $dsn .= ';skip_verify=true';
            }
            if (!empty($config['ssl_ca_path'])) {
                $dsn .= ';ca_path=' . $config['ssl_ca_path'];
            }
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

        /* Persistent connections reuse the underlying ClickHouse native
         * TCP handshake across PHP-FPM requests, which can be a sizable
         * wall-time win on short API responses that would otherwise
         * repay the auth / database-selection handshake for every
         * request. Opt-in via the 'persistent' connection flag so
         * operators who prefer per-request isolation can stay on the
         * default behavior. */
        if (!empty($config['persistent'])) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        return new PDO(
            $dsn,
            $config['username'] ?? 'default',
            $config['password'] ?? '',
            $options,
        );
    }
}
