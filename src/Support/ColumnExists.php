<?php

namespace NotificationChannels\Zapmizer\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Class ColumnExists.
 *
 * Some columns come from an optional migration (`webhook_key`, published by
 * `zapmizer-migrations-webhook-key`). Code that needs one asks here first,
 * so a missing migration is reported instead of failing mid-flow.
 *
 * A positive answer is cached for a day (the column does not go away); a
 * negative one is re-checked on every call, so running the migration is
 * picked up at once.
 */
final class ColumnExists
{
    public static function for(Model $model, string $column): bool
    {
        $key = 'zapmizer:column-exists:' . $model->getTable() . '.' . $column;

        if (Cache::get($key) === true) {
            return true;
        }

        $exists = $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);

        if ($exists) {
            Cache::put($key, true, now()->addDay());
        }

        return $exists;
    }
}
