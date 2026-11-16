<?php

namespace NotificationChannels\Zapmizer\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Zapmizer\Contracts\Connectable;
use NotificationChannels\Zapmizer\Exceptions\NoConnectableException;
use NotificationChannels\Zapmizer\Exceptions\NotConnectableException;
use ReflectionMethod;
use TypeError;

/**
 * Asks the controller's `$resolver` which model the request is about.
 *
 * A resolver with nothing to connect throws NoConnectableException, which
 * the routes answer with 403 `no_connectable`. A resolver that hands back
 * something that is not `Model&Connectable` is a setup defect, answered the
 * same way instead of a 500, with a warning naming the resolver and what it
 * returned: the default resolver with a user model that lacks Connectable
 * (NotConnectableException), or the app's own resolver failing its return
 * type (a model without Connectable, or null). Any other TypeError is a bug
 * inside the resolver and goes up untouched.
 */
trait ResolvesTheConnectable
{
    /**
     * @throws NoConnectableException
     */
    protected function resolveConnectable(Request $request): Model&Connectable
    {
        try {
            return $this->resolver->resolve($request);
        } catch (NotConnectableException $exception) {
            $this->warnNotConnectable($exception->getMessage(), $request->user());

            throw new NoConnectableException($exception->getMessage(), 0, $exception);
        } catch (TypeError $error) {
            $returned = $this->resolverReturnType($error);

            if ($returned === null) {
                throw $error;
            }

            $this->warnNotConnectable($error->getMessage(), $returned);

            throw new NoConnectableException($error->getMessage(), 0, $error);
        }
    }

    /**
     * What the resolver returned, from the TypeError of its own return type
     * (`Resolver::resolve(): Return value must be of type ..., X returned`);
     * null when the error is anything else.
     */
    private function resolverReturnType(TypeError $error): ?string
    {
        $declaring = (new ReflectionMethod($this->resolver, 'resolve'))->class;
        $prefix = $declaring . '::resolve(): Return value must be of type ';

        if (!str_starts_with($error->getMessage(), $prefix)) {
            return null;
        }

        return preg_match('/, (\S+) returned$/', $error->getMessage(), $match) === 1 ? $match[1] : 'unknown';
    }

    private function warnNotConnectable(string $reason, mixed $returned): void
    {
        Log::warning('zapmizer: the connect resolver returned a model that is not Connectable.', [
            'resolver' => get_class($this->resolver),
            'returned' => is_string($returned) ? $returned : get_debug_type($returned),
            'reason' => $reason,
        ]);
    }
}
