<?php

namespace NotificationChannels\Zapmizer\Support;

use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * Class JsonResponse.
 *
 * Tells whether an answer from Zapmizer is the JSON the API promises. With a
 * revoked or invalid token Zapmizer answers a redirect to its login page;
 * followed, it lands on a 200 HTML page and a send "succeeds" with the
 * message lost. The clients disable redirects and run every response
 * through here before trusting it.
 */
final class JsonResponse
{
    /**
     * The reason the response is NOT an API answer — null when it is. A
     * response without a Content-Type is judged by its body, unless
     * `$sniffBody` is off (endpoints that legitimately answer empty).
     */
    public static function problem(ResponseInterface $response, bool $sniffBody = true): ?string
    {
        if (($redirected = self::redirected($response)) !== null) {
            return $redirected;
        }

        $contentType = $response->getHeaderLine('Content-Type');

        if ($contentType !== '') {
            return self::isJson($contentType) ? null : "unexpected content type `{$contentType}`";
        }

        if (!$sniffBody) {
            return null;
        }

        // No content type at all: judge by the body, which is left readable.
        $body = $response->getBody();
        $contents = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return json_decode($contents) === null && json_last_error() !== JSON_ERROR_NONE
            ? 'response body is not valid JSON'
            : null;
    }

    /**
     * Only the redirect leg of the check — for an endpoint whose success is
     * not JSON (media bytes) but whose refused token still redirects.
     */
    public static function redirected(ResponseInterface $response): ?string
    {
        $status = $response->getStatusCode();

        if ($status < 300 || $status >= 400) {
            return null;
        }

        $location = $response->getHeaderLine('Location');

        return "redirected ({$status}" . ($location !== '' ? " to {$location}" : '') . ') — the API token is probably invalid or revoked';
    }

    protected static function isJson(string $contentType): bool
    {
        $type = strtolower(trim(Str::before($contentType, ';')));

        return $type === 'application/json' || Str::endsWith($type, '+json');
    }
}
