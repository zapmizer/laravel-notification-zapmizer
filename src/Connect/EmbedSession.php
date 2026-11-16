<?php

namespace NotificationChannels\Zapmizer\Connect;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Support\Payload;

final class EmbedSession
{
    public function __construct(
        public readonly string $url,
        public readonly string $origin,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $resumeUrl = null,
        public readonly ?CarbonImmutable $resumeUntil = null,
    ) {
    }

    /**
     * @throws ZapmizerConnectException
     */
    public static function fromArray(array $payload): self
    {
        $url = Payload::url($payload['url'] ?? null);

        if ($url === null) {
            throw ZapmizerConnectException::unexpectedResponse('invalid embed url');
        }

        $origin = Payload::origin($url);

        $resumeUrl = self::resumeUrl($payload['resume_url'] ?? null, $origin);

        return new self(
            url: $url,
            origin: $origin,
            expiresAt: Payload::date($payload['expires_at'] ?? null, 'expires_at'),
            resumeUrl: $resumeUrl,
            resumeUntil: $resumeUrl === null ? null : Payload::date($payload['resume_until'] ?? null, 'resume_until'),
        );
    }

    private static function resumeUrl(mixed $value, string $origin): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $url = Payload::url($value);
        $resumeOrigin = $url === null ? null : Payload::origin($url);

        if ($resumeOrigin !== $origin) {
            Log::warning('zapmizer: unexpected resume url.', [
                'resume_origin' => $resumeOrigin,
                'url_origin' => $origin,
            ]);

            return null;
        }

        return $url;
    }
}
