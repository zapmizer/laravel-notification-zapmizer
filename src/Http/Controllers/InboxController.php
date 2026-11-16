<?php

namespace NotificationChannels\Zapmizer\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Contracts\ResolvesConnectable;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\ZapmizerApiException;
use NotificationChannels\Zapmizer\Http\Responses\EmbedSessionResponse;

/**
 * Class InboxController.
 *
 * The backend of the embedded inbox (`zapmizer.inbox.enabled`): `session`
 * is the endpoint the `openSession` of @zapmizer/embed calls, and
 * `keepalive` the one its `keepAlive` pings. The connection is the one of
 * the model `zapmizer.connect.resolver` returns — the same the connect flow
 * fills — and the attendant is the logged-in user (`user.id`/`user.name`).
 *
 * The answer is the EmbedSessionResponse's: 200 `{url, origin, resume_url,
 * resume_until}`, or `{code}` with the status the front expects (Zapmizer's
 * 401 as 422 `reauth_required`, 5xx and network as 503 `unavailable`).
 * Two codes are the package's: 403 `no_connectable` (the resolver found
 * nothing to open the inbox for) and 409 `not_connected` (no connection yet:
 * the screen offers the connect button).
 */
class InboxController extends Controller
{
    public const NO_CONNECTABLE = 'no_connectable';

    public const NOT_CONNECTED = 'not_connected';

    public function __construct(protected ResolvesConnectable $resolver)
    {
    }

    public function session(Request $request): Responsable
    {
        try {
            $connectable = $this->resolver->resolve($request);
        } catch (NoConnectableException) {
            return EmbedSessionResponse::code(self::NO_CONNECTABLE, 403);
        }

        $connection = $connectable->zapmizerConnection;

        if ($connection === null) {
            return EmbedSessionResponse::code(self::NOT_CONNECTED, 409);
        }

        $user = $request->user();
        $parentOrigin = $this->parentOrigin($request);
        $appearance = $this->appearance($request);

        return EmbedSessionResponse::open(
            fn () => $connection->instanceClient()->inboxSession(
                parentOrigin: $parentOrigin,
                appearance: $appearance,
                userId: $this->userId($user),
                userName: $this->userName($user),
            ),
            onRefusal: fn (ZapmizerApiException $refusal) => Log::warning('zapmizer: inbox session refused.', [
                'connection_id' => $connection->getKey(),
                'status' => $refusal->status(),
                'code' => $refusal->error(),
            ]),
        );
    }

    /**
     * What the inbox pings while it is open: a 2xx keeps it, the app's own
     * 401/419 (session gone) closes it.
     */
    public function keepalive(): Response
    {
        return new Response('', 204);
    }

    /**
     * `zapmizer.inbox.parent_origin` when set, else the request's origin.
     */
    protected function parentOrigin(Request $request): string
    {
        $configured = config('zapmizer.inbox.parent_origin');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return $request->getSchemeAndHttpHost();
    }

    /**
     * Only `theme` comes from the front, and only a known value: anything
     * else is left out instead of refused, so a stray field never turns the
     * inbox into a 422 the front reads as `unavailable`.
     *
     * @return array<string, string>
     */
    protected function appearance(Request $request): array
    {
        $theme = $request->input('theme');

        return in_array($theme, ['light', 'dark'], true) ? ['theme' => $theme] : [];
    }

    protected function userId(mixed $user): ?string
    {
        if (!is_object($user) || !method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * The `name` attribute, cut to the 100 characters Zapmizer accepts (more
     * is a 422 that would close the inbox for a long name).
     */
    protected function userName(mixed $user): ?string
    {
        $name = $user instanceof Model ? $user->getAttribute('name') : null;

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : null;
    }
}
