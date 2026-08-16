<?php

namespace ClickHouse\Laravel\Query\Concerns;

use ClickHouse\Laravel\Support\ClickHouseSql;

/**
 * ON CLUSTER runs DDL and mutations on a named ClickHouse cluster.
 *
 * Usage:
 *   ->onCluster('my_cluster')->where('id', 1)->delete()
 */
trait HasOnCluster
{
    public ?string $clusterName = null;

    public function onCluster(string $cluster): static
    {
        $this->clusterName = ClickHouseSql::nonEmpty($cluster, 'cluster name');

        return $this;
    }
}
