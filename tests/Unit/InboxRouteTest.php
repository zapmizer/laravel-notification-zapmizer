<?php

namespace NotificationChannels\Zapmizer\Test\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use NotificationChannels\Zapmizer\Test\Fixtures\CreatesConnectionTables;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesFirstTeam;
use NotificationChannels\Zapmizer\Test\Fixtures\ResolvesNothing;
use NotificationChannels\Zapmizer\Test\Fixtures\Team;
use NotificationChannels\Zapmizer\Test\Fixtures\User;
use NotificationChannels\Zapmizer\Test\TestCase;

class InboxRouteTest extends TestCase
{
    use CreatesConnectionTables;

    private const SESSION = [
        'url' => 'https://app.parlichat.com/embed-inbox/start/Zr8kQ2',
        'expires_at' => '2026-09-26T14:01:00+00:00',
        'resume_url' => 'https://app.parlichat.com/chats?embed_inbox=abc',
        'resume_until' => '2026-09-26T16:00:00+00:00',
    ];

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('zapmizer.base_uri', 'http://zap.test/api/');
        $app['config']->set('zapmizer.inbox.enabled', true);
    }

    protected function connectedUser(array $overrides = [], string $name = 'Ana Atendente'): User
    {
        $user = User::create(['name' => $name]);

        $user->zapmizerConnection()->create(array_merge([
            'api_token' => 'user-token',
            'zapmizer_team_id' => 7,
            'phone_number' => '5581911110000',
            'bot_instance_id' => 9,
            'connected_at' => now(),
            'is_active' => true,
        ], $overrides));

        $this->actingAs($user);

        return $user;
    }

    protected function fakeSession(int $status = 201, array $body = self::SESSION, array $headers = []): void
    {
        Http::fake(['zap.test/api/embed/sessions' => Http::response($body, $status, $headers)]);
    }

    public function testItOpensTheInboxOfTheUsersConnectionAsTheUser()
    {
        $user = $this->connectedUser();
        $this->fakeSession();

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertOk()
            ->assertExactJson([
                'url' => 'https://app.parlichat.com/embed-inbox/start/Zr8kQ2',
                'origin' => 'https://app.parlichat.com',
                'resume_url' => 'https://app.parlichat.com/chats?embed_inbox=abc',
                'resume_until' => '2026-09-26T16:00:00+00:00',
            ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($user) {
            return $request->method() === 'POST'
                && $request->url() === 'http://zap.test/api/embed/sessions'
                && $request->hasHeader('Authorization', 'Bearer user-token')
                && $request->data() === [
                    'component' => 'inbox',
                    'parent_origin' => 'http://localhost',
                    'user' => ['id' => (string) $user->id, 'name' => 'Ana Atendente'],
                ];
        });
    }

    public function testTheConnectionComesFromTheConnectResolver()
    {
        $this->app['config']->set('zapmizer.connect.resolver', ResolvesFirstTeam::class);

        $this->actingAs(User::create(['name' => 'Bia']));
        Team::firstOrCreate(['name' => 'Acme'])->zapmizerConnection()->create([
            'api_token' => 'team-token',
            'phone_number' => '5581911110000',
            'is_active' => true,
        ]);
        $this->fakeSession();

        $this->postJson(route('zapmizer.inbox.session'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer team-token')
            && $request['user']['name'] === 'Bia');
    }

    public function testAKnownThemeGoesAsAppearanceAndAnythingElseIsLeftOut()
    {
        $this->connectedUser();
        $this->fakeSession();

        $this->postJson(route('zapmizer.inbox.session'), ['theme' => 'dark', 'color_primary' => 'red'])->assertOk();
        $this->postJson(route('zapmizer.inbox.session'), ['theme' => 'neon'])->assertOk();

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();

        $this->assertSame(['theme' => 'dark'], $sent[0]['appearance']);
        $this->assertArrayNotHasKey('appearance', $sent[1]);
    }

    public function testTheConfiguredParentOriginWinsOverTheRequest()
    {
        $this->app['config']->set('zapmizer.inbox.parent_origin', ' https://app.example.com/ ');
        $this->connectedUser();
        $this->fakeSession();

        $this->postJson(route('zapmizer.inbox.session'))->assertOk();

        Http::assertSent(fn (Request $request) => $request['parent_origin'] === 'https://app.example.com');
    }

    public function testALongNameIsCutToWhatZapmizerAccepts()
    {
        $this->connectedUser([], str_repeat('á', 120));
        $this->fakeSession();

        $this->postJson(route('zapmizer.inbox.session'))->assertOk();

        Http::assertSent(fn (Request $request) => $request['user']['name'] === str_repeat('á', 100));
    }

    public function testWithoutAConnectionItAnswersNotConnectedWithoutCallingZapmizer()
    {
        $this->actingAs(User::create(['name' => 'Ana']));
        Http::fake();

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(409)
            ->assertExactJson(['code' => 'not_connected']);

        Http::assertNothingSent();
    }

    public function testNothingToResolveIsNoConnectable()
    {
        $this->app['config']->set('zapmizer.connect.resolver', ResolvesNothing::class);
        $this->actingAs(User::create(['name' => 'Ana']));
        Http::fake();

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(403)
            ->assertExactJson(['code' => 'no_connectable']);

        Http::assertNothingSent();
    }

    public function testAConnectionWithoutTokenAsksForANewConnect()
    {
        $this->connectedUser(['api_token' => null]);
        Http::fake();

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(422)
            ->assertExactJson(['code' => 'reauth_required']);

        Http::assertNothingSent();
    }

    public function testARevokedTokenIsReauthRequiredNeverA401()
    {
        $this->connectedUser();
        $this->fakeSession(401, ['message' => 'Unauthenticated.']);

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(422)
            ->assertExactJson(['code' => 'reauth_required']);
    }

    public function testRateLimitedCarriesTheRetryAfter()
    {
        $this->connectedUser();
        $this->fakeSession(429, ['error' => 'too_many_requests'], ['Retry-After' => '30']);

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(429)
            ->assertExactJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After', '30');
    }

    public function testARefusalGoesToTheFrontAsCodeAndToTheLog()
    {
        Log::spy();
        $user = $this->connectedUser();
        $this->fakeSession(403, ['error' => 'missing_ability', 'message' => 'The token lacks inbox:attend.']);

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(403)
            ->assertExactJson(['code' => 'missing_ability']);

        Log::shouldHaveReceived('warning')->with('zapmizer: inbox session refused.', [
            'connection_id' => $user->zapmizerConnection->getKey(),
            'status' => 403,
            'code' => 'missing_ability',
        ])->once();
    }

    public function testZapmizerDownIsUnavailable()
    {
        $this->connectedUser();
        $this->fakeSession(502, ['message' => 'Bad gateway']);

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'unavailable']);
    }

    public function testANetworkFailureIsUnavailable()
    {
        $this->connectedUser();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->postJson(route('zapmizer.inbox.session'))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'unavailable']);
    }

    public function testTheKeepaliveAnswers204ToTheLoggedInUser()
    {
        $this->actingAs(User::create(['name' => 'Ana']));

        $this->getJson(route('zapmizer.inbox.keepalive'))
            ->assertNoContent();
    }

    public function testAGuestGets401FromBothRoutesSoTheInboxCloses()
    {
        Http::fake();

        $this->postJson(route('zapmizer.inbox.session'))->assertStatus(401);
        $this->getJson(route('zapmizer.inbox.keepalive'))->assertStatus(401);

        Http::assertNothingSent();
    }

    public function testTheSessionRouteIsAWebRouteWithCsrfAndThrottle()
    {
        $route = Route::getRoutes()->getByName('zapmizer.inbox.session');

        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
        $this->assertSame('zapmizer/inbox/session', $route->uri());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());

        $keepalive = Route::getRoutes()->getByName('zapmizer.inbox.keepalive');
        $this->assertSame('zapmizer/inbox/keepalive', $keepalive->uri());
        $this->assertContains('GET', $keepalive->methods());
    }
}
