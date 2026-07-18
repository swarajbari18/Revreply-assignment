<?php

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['gemini.api_key' => 'fake-gemini-key']);
        config(['services.workflow.run_full_pipeline' => false]);
    }

    protected function mockGmailClient(array $responses): void
    {
        $guzzleMock = new Client([
            'handler' => HandlerStack::create(
                new MockHandler($responses)
            ),
        ]);

        $client = $this->app->make(\Google\Client::class);
        $client->setHttpClient($guzzleMock);
    }
}
