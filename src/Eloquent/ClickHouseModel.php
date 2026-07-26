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
 * - Mass assignment remains guarded until a model defines $fillable or $guarded
 *
 * Usage:
 *   class Event extends ClickHouseModel
 *   {
 *       protected $casts = ['user_id' => 'integer'];
 *   }
 */
abstract class ClickHouseModel extends Model
{
    protected static string $builder = Builder::class;

    protected $connection = 'clickhouse';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'id';
}
