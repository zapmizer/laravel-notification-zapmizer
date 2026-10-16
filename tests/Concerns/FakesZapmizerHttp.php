<?php

namespace NotificationChannels\Zapmizer\Test\Concerns;

use GuzzleHttp\Handler\MockHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

trait FakesZapmizerHttp
{
    protected function fakeZapmizer(MockHandler $mock): void
    {
        Http::fake(function (Request $request, array $options) use ($mock) {
            $psr = $request->toPsrRequest();
            $response = $mock($psr, $options);
            $this->history[] = ['request' => $psr, 'options' => $options];

            return $response;
        });
    }
}
