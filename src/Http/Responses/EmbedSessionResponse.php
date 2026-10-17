<?php

namespace NotificationChannels\Zapmizer\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use NotificationChannels\Zapmizer\Connect\EmbedSession;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerConnectException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerUnavailableException;
use UnexpectedValueException;

class EmbedSessionResponse implements Responsable
{
    public const REAUTH_REQUIRED = 'reauth_required';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';

    final protected function __construct(
        private ?EmbedSession $session = null,
        private ?ZapmizerConnectException $failure = null,
        private ?string $code = null,
        private int $status = 200,
    ) {
    }

    /**
     * @param callable(): EmbedSession $open
     * @param (callable(ZapmizerApiException): mixed)|null $onRefusal
     */
    public static function open(callable $open, ?callable $onRefusal = null): static
    {
        try {
            $session = $open();
        } catch (ZapmizerApiException $exception) {
            if ($onRefusal !== null) {
                $onRefusal($exception);
            }

            return new static(failure: $exception);
        } catch (ZapmizerUnavailableException $exception) {
            return new static(failure: $exception);
        } catch (ZapmizerConnectException $exception) {
            report($exception);

            return new static(failure: $exception);
        }

        return new static(session: $session);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function code(string $code, int $status = 422): static
    {
        if ($code === '' || $status < 400 || $status > 599 || $status === 401 || $status === 419) {
            throw new InvalidArgumentException('An embed refusal needs a non-empty code and a 4xx or 5xx status other than 401 and 419.');
        }

        return new static(code: $code, status: $status);
    }

    /**
     * @param \Illuminate\Http\Request $request
     *
     * @throws UnexpectedValueException
     */
    public function toResponse($request): \Illuminate\Http\JsonResponse
    {
        if ($this->session !== null) {
            return new JsonResponse($this->sessionPayload($this->session), 200);
        }

        if ($this->failure === null) {
            return new JsonResponse(['code' => $this->code], $this->status);
        }

        if (!$this->failure instanceof ZapmizerApiException) {
            return new JsonResponse(['code' => self::UNAVAILABLE], 503);
        }

        $status = $this->failure->status();

        if ($status === 401 || $status === 419) {
            return new JsonResponse(['code' => self::REAUTH_REQUIRED], 422);
        }

        if ($status === 429) {
            $retryAfter = $this->failure->apiError()->retryAfter;

            return new JsonResponse(
                ['code' => self::RATE_LIMITED],
                429,
                $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
            );
        }

        $status = $this->refusalStatus($this->failure);

        if ($status < 400 || $status > 599 || $status === 401 || $status === 419) {
            throw new UnexpectedValueException(sprintf(
                '%s::refusalStatus() returned %d: a refusal is a 4xx or 5xx other than 401 and 419.',
                static::class,
                $status,
            ));
        }

        return new JsonResponse(['code' => $this->refusalCode($this->failure)], $status);
    }

    protected function sessionPayload(EmbedSession $session): array
    {
        return [
            'url' => $session->url,
            'origin' => $session->origin,
            'resume_url' => $session->resumeUrl,
            'resume_until' => $session->resumeUntil?->toIso8601String(),
        ];
    }

    protected function refusalStatus(ZapmizerApiException $refusal): int
    {
        return $refusal->status();
    }

    protected function refusalCode(ZapmizerApiException $refusal): ?string
    {
        return $refusal->error();
    }
}
