<?php

namespace ClickHouse\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model for ClickHouse tables.
 *
 * Sets the sensible defaults that every ClickHouse model needs:
 * - No auto-incrementing IDs (ClickHouse has no sequences)
 * - No automatic timestamps (managed manually or via traits)
 * - String primary key (UUIDs are common in ClickHouse)
 * - Unguarded by default (ClickHouse is append-only, mass assignment risk is low)
 *
 * Usage:
 *   class Event extends ClickHouseModel
 *   {
 *       protected $casts = ['user_id' => 'integer'];
 *   }
 */
abstract class ClickHouseModel extends Model
{
    protected $connection = 'clickhouse';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'id';

    protected $guarded = [];
}
