<?php

namespace NotificationChannels\Zapmizer\Support;

use Psr\Http\Message\ResponseInterface;

final class ApiError
{
    public const BODY_LIMIT = 500;

    /**
     * @param array<string, string[]> $errors
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly int $status,
        public readonly ?string $error,
        public readonly ?string $message,
        public readonly array $errors,
        public readonly array $payload,
        public readonly ?int $retryAfter,
        public readonly string $body,
    ) {
    }

    public static function from(ResponseInterface $response): self
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $payload = is_array($decoded) && !array_is_list($decoded) ? $decoded : [];

        return new self(
            status: $response->getStatusCode(),
            error: self::errorCode($payload),
            message: self::messageOf($payload),
            errors: self::fieldErrors($payload['errors'] ?? null),
            payload: $payload,
            retryAfter: self::seconds($response->getHeaderLine('Retry-After')),
            body: mb_substr(trim($body), 0, self::BODY_LIMIT),
        );
    }

    public static function unauthenticated(string $message): self
    {
        return new self(
            status: 401,
            error: null,
            message: $message,
            errors: [],
            payload: [],
            retryAfter: null,
            body: '',
        );
    }

    /**
     * The message followed by the first error of each field. The very first
     * error is left out when the message already carries it: Laravel's 422
     * `message` is that error (plus "(and N more errors)").
     */
    public function reason(): string
    {
        $parts = [$this->message];
        $first = true;

        foreach ($this->errors as $messages) {
            if (!$first || !$this->carries($this->message, $messages[0])) {
                $parts[] = $messages[0];
            }

            $first = false;
        }

        $reason = implode(' ', array_filter($parts, fn (?string $part) => $part !== null && trim($part) !== ''));

        return $reason !== '' ? $reason : $this->body;
    }

    private function carries(?string $message, string $error): bool
    {
        $error = trim($error);

        return $message !== null && $error !== '' && str_contains(mb_strtolower($message), mb_strtolower($error));
    }

    private static function errorCode(array $payload): ?string
    {
        $error = $payload['error'] ?? null;

        return is_string($error) && $error !== '' ? $error : null;
    }

    private static function messageOf(array $payload): ?string
    {
        if (is_string($payload['message'] ?? null)) {
            return $payload['message'];
        }

        $error = $payload['error'] ?? null;

        return is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;
    }

    /**
     * @return array<string, string[]>
     */
    private static function fieldErrors(mixed $errors): array
    {
        if (!is_array($errors) || $errors === [] || array_is_list($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            $strings = match (true) {
                is_string($messages) => [$messages],
                is_array($messages) => array_values(array_filter($messages, 'is_string')),
                default => [],
            };

            if ($strings !== []) {
                $normalized[(string) $field] = $strings;
            }
        }

        return $normalized;
    }

    private static function seconds(string $header): ?int
    {
        $header = trim($header);

        return ctype_digit($header) ? (int) $header : null;
    }
}
