<?php

namespace NotificationChannels\Zapmizer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class WhatsappVerified.
 *
 * Holds the WhatsApp verification state for a single user (1:1). Extend this
 * model and point `zapmizer.models.whatsapp_verified` at your subclass to
 * customize it.
 */
class WhatsappVerified extends Model
{
    /** Verification started, waiting for the user to complete it. */
    public const STATUS_AWAITING = 'awaiting';

    /** Number confirmed. */
    public const STATUS_VERIFIED = 'verified';

    /** Verification ended without success (expired or too many wrong codes). */
    public const STATUS_FAILED = 'failed';

    protected $table = 'whatsapp_verifieds';

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    /**
     * The user whose WhatsApp number is being verified.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'user_id');
    }

    public function isAwaiting(): bool
    {
        return $this->status === static::STATUS_AWAITING;
    }

    public function isVerified(): bool
    {
        return $this->status === static::STATUS_VERIFIED && $this->verified_at !== null;
    }
}
