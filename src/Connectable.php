<?php

namespace NotificationChannels\Zapmizer;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;

/**
 * Trait Connectable.
 *
 * The `Billable` of this package: put it on the model that owns a WhatsApp
 * number (a Team, a User, ...) together with the Contracts\Connectable
 * interface, and it gains its own Zapmizer connection — the hosted connect
 * flow fills it, and every message goes out from the number that model
 * paired.
 *
 * <code>
 * use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
 * use NotificationChannels\Zapmizer\Contracts\Connectable;
 *
 * class Team extends Model implements Connectable
 * {
 *     use ConnectsZapmizer;
 * }
 *
 * $team->zapmizerMessage('5511999999999')->text('Hello')->send();
 * </code>
 */
trait Connectable
{
    /**
     * The Zapmizer connection owned by the model.
     */
    public function zapmizerConnection(): MorphOne
    {
        return $this->morphOne($this->zapmizerConnectionModel(), 'connectable');
    }

    /**
     * Whether the model has an active connection with a paired number.
     */
    public function hasActiveZapmizer(): bool
    {
        $connection = $this->zapmizerConnection;

        return $connection !== null && $connection->isConnected();
    }

    /**
     * The model's connection, or the started (unsaved) one.
     */
    public function zapmizerConnectionOrNew(): ZapmizerConnection
    {
        return $this->zapmizerConnection ?? $this->zapmizerConnection()->make(['is_active' => false]);
    }

    /**
     * A messages client authenticated as the model's connection.
     *
     * @throws ZapmizerConnectException
     */
    public function zapmizer(): Zapmizer
    {
        $connection = $this->zapmizerConnection;

        if ($connection === null || blank($connection->api_token)) {
            throw ZapmizerConnectException::notConnected();
        }

        return $connection->zapmizer();
    }

    /**
     * A message from the model's paired number to `$to`.
     *
     * @throws ZapmizerConnectException
     */
    public function zapmizerMessage(string $to): ZapmizerMessage
    {
        $connection = $this->zapmizerConnection;

        if ($connection === null) {
            throw ZapmizerConnectException::notConnected();
        }

        return $connection->message($to);
    }

    /**
     * The model class used to store the connection.
     *
     * @return class-string<ZapmizerConnection>
     */
    protected function zapmizerConnectionModel(): string
    {
        return config('zapmizer.models.connection', ZapmizerConnection::class);
    }
}
