<?php

namespace NotificationChannels\Zapmizer\Http\Responses;

use NotificationChannels\Zapmizer\Exceptions\ErrorCode;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;

/**
 * Class InboxSessionResponse.
 *
 * The answer of the package's inbox route: the EmbedSessionResponse, plus
 * the two 403s a new connect fixes turned into `reauth_required` (422),
 * which @zapmizer/embed shows with the `reconnect` action. Zapmizer answers
 * `missing_ability` to a token without `inbox:attend` (connections made
 * before the inbox existed) and `not_a_partner_connection` to a token that
 * did not come from the connect; the connect issues one with both.
 */
class InboxSessionResponse extends EmbedSessionResponse
{
    public const RECONNECT_CODES = [ErrorCode::MISSING_ABILITY, ErrorCode::NOT_A_PARTNER_CONNECTION];

    protected function refusalStatus(ZapmizerApiException $refusal): int
    {
        return $this->asksForConnect($refusal) ? 422 : parent::refusalStatus($refusal);
    }

    protected function refusalCode(ZapmizerApiException $refusal): ?string
    {
        return $this->asksForConnect($refusal) ? self::REAUTH_REQUIRED : parent::refusalCode($refusal);
    }

    private function asksForConnect(ZapmizerApiException $refusal): bool
    {
        return $refusal->status() === 403 && in_array($refusal->error(), self::RECONNECT_CODES, true);
    }
}
