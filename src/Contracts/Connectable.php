<?php

namespace NotificationChannels\Zapmizer\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use NotificationChannels\Zapmizer\Models\ZapmizerConnection;
use NotificationChannels\Zapmizer\Zapmizer;
use NotificationChannels\Zapmizer\ZapmizerMessage;

/**
 * Interface Connectable.
 *
 * The contract of a model that owns a Zapmizer connection — the pair of the
 * `Connectable` trait, the way `MustVerifyWhatsapp` pairs with its trait.
 * Implement it on the model together with the trait:
 *
 * <code>
 * use NotificationChannels\Zapmizer\Connectable as ConnectsZapmizer;
 * use NotificationChannels\Zapmizer\Contracts\Connectable;
 *
 * class Team extends Model implements Connectable
 * {
 *     use ConnectsZapmizer;
 * }
 * </code>
 *
 * The connect flow's resolver must return a model implementing it — the
 * type is what turns a mis-configured resolver into a clear error instead
 * of a `method_exists` surprise deep in the controller.
 */
interface Connectable
{
    /**
     * The Zapmizer connection owned by the model.
     */
    public function zapmizerConnection(): MorphOne;

    /**
     * Whether the model has an active connection with a paired number.
     */
    public function hasActiveZapmizer(): bool;

    /**
     * The model's connection, or the started (unsaved) one.
     */
    public function zapmizerConnectionOrNew(): ZapmizerConnection;

    /**
     * A messages client authenticated as the model's connection.
     */
    public function zapmizer(): Zapmizer;

    /**
     * A message from the model's paired number to `$to`.
     */
    public function zapmizerMessage(string $to): ZapmizerMessage;
}
