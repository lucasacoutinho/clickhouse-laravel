<?php

namespace ClickHouse\Laravel\Concerns;

/**
 * Auto-manages created_at and updated_at for ClickHouse models.
 *
 * Unlike Eloquent's built-in timestamps, this doesn't rely on
 * the database to have an updated_at trigger. Instead, it sets
 * both columns on creating and updated_at on saving.
 *
 * Useful with ReplacingMergeTree(updated_at) where the latest
 * updated_at wins during deduplication.
 *
 * Usage:
 *   class User extends ClickHouseModel
 *   {
 *       use HasClickHouseTimestamps;
 *   }
 */
trait HasClickHouseTimestamps
{
    public static function bootHasClickHouseTimestamps(): void
    {
        static::creating(function (self $model) {
            $model->created_at ??= now();
            $model->updated_at ??= now();
        });
    }

    public function touch($attribute = null): bool
    {
        if ($attribute) {
            $this->{$attribute} = now();
        } else {
            $this->updated_at = now();
        }

        return $this->save();
    }
}
