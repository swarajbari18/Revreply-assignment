<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    protected function mockGmailClient(array $responses): void
    {
        $guzzleMock = new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create(
                new \GuzzleHttp\Handler\MockHandler($responses)
            ),
        ]);

        $client = $this->app->make(\Google\Client::class);
        $client->setHttpClient($guzzleMock);
    }
}
