<?php

namespace NotificationChannels\Zapmizer\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use NotificationChannels\Zapmizer\Testing\FakeSend;
use NotificationChannels\Zapmizer\Testing\ZapmizerFake;
use NotificationChannels\Zapmizer\Zapmizer as ZapmizerClient;
use RuntimeException;

/**
 * Facade of the messages client (`NotificationChannels\Zapmizer\Zapmizer`,
 * made with the config's token) and the way to fake every send:
 *
 * <code>
 * use NotificationChannels\Zapmizer\Facades\Zapmizer;
 * use NotificationChannels\Zapmizer\Testing\FakeSend;
 *
 * Zapmizer::fake();
 * // ... the code under test sends, by any path of the package
 * Zapmizer::assertSent(fn (FakeSend $send) => $send->to === '5511999999999');
 * </code>
 *
 * The facade has the client's short name: in a file that also uses the
 * client class, alias one of them (`use NotificationChannels\Zapmizer\Zapmizer
 * as ZapmizerClient;`). And `$this->mock(Zapmizer::class)` must name the
 * client, not this facade.
 *
 * @method static \NotificationChannels\Zapmizer\Messages\SentMessage sendMessage(array $params)
 * @method static \NotificationChannels\Zapmizer\Messages\SentMessage sendMessageWithFile(array $params, string $filePath)
 * @method static \NotificationChannels\Zapmizer\Messages\SentTemplate sendTemplate(string $from, string $to, string $template, ?string $language = null, array $body = [], ?string $header = null)
 * @method static \NotificationChannels\Zapmizer\Messages\QrMessageStatus|\NotificationChannels\Zapmizer\Messages\MetaMessageStatus|null track(\NotificationChannels\Zapmizer\Messages\SentMessage|string|null $sent)
 * @method static \NotificationChannels\Zapmizer\Messages\QrMessageStatus sentMessage(string $id)
 * @method static \NotificationChannels\Zapmizer\Messages\MetaMessageStatus metaMessage(int|string $id)
 * @method static string|null getToken()
 * @method static string getApiBaseUri()
 *
 * @see ZapmizerClient
 * @see ZapmizerFake
 */
class Zapmizer extends Facade
{
    /**
     * Fake every send of the package from here on (see ZapmizerFake): no
     * request leaves, and the assertions below read what was sent.
     */
    public static function fake(): ZapmizerFake
    {
        $fake = new ZapmizerFake();

        static::getFacadeApplication()->instance(ZapmizerFake::class, $fake);

        return $fake;
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public static function assertSent(?callable $callback = null): void
    {
        static::faked()->assertSent($callback);
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public static function assertSentTimes(int $times, ?callable $callback = null): void
    {
        static::faked()->assertSentTimes($times, $callback);
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     */
    public static function assertNotSent(?callable $callback = null): void
    {
        static::faked()->assertNotSent($callback);
    }

    public static function assertNothingSent(): void
    {
        static::faked()->assertNothingSent();
    }

    /**
     * @param (callable(FakeSend): bool)|null $callback
     *
     * @return Collection<int, FakeSend>
     */
    public static function sent(?callable $callback = null): Collection
    {
        return static::faked()->sent($callback);
    }

    protected static function faked(): ZapmizerFake
    {
        $app = static::getFacadeApplication();

        if ($app === null || !$app->bound(ZapmizerFake::class)) {
            throw new RuntimeException('Call Zapmizer::fake() before asserting on the sends.');
        }

        return $app->make(ZapmizerFake::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return ZapmizerClient::class;
    }
}
