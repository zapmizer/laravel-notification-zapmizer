<?php

namespace NotificationChannels\Zapmizer\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Class TableExists.
 *
 * The package ships two migrations (`whatsapp_verifieds`, `zapmizer_connections`)
 * and an application may publish only one of them. Webhook code paths that
 * touch the other table ask here first, so a delivery is acknowledged
 * instead of answering 500 on a table that was never created.
 *
 * A positive answer is cached for a day (the table does not go away); a
 * negative one is re-checked on every call, so running the migration is
 * picked up at once.
 */
final class TableExists
{
    public static function for(Model $model): bool
    {
        $key = 'zapmizer:table-exists:' . $model->getTable();

        if (Cache::get($key) === true) {
            return true;
        }

        $exists = $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());

        if ($exists) {
            Cache::put($key, true, now()->addDay());
        }

        return $exists;
    }
}
