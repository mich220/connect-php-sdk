<?php

namespace Test\Unit\Runtime\Providers;

use Connect\Runtime\ServiceProvider;
use Pimple\Container;

/**
 * Class Http4TierConfigServiceProvider
 * @package Test\Unit\Runtime\Providers
 */
class Http4TierConfigServiceProvider extends ServiceProvider
{
    public function register(Container $container)
    {
        $body = \Mockery::mock('\Psr\Http\Message\StreamInterface');

        $body->shouldReceive('getContents')
            ->times(3)
            ->andReturn(trim(file_get_contents(__DIR__ . '/request.valid.TierConfigTests.json')),
                trim(file_get_contents(__DIR__ . '/request.valid.TierConfigTests.json')),
                "[]");
        $response = \Mockery::mock('\Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('getStatusCode')
            ->andReturn(200);

        $response->shouldReceive('getBody')
            ->andReturn($body);

        $client = \Mockery::mock('Client');
        $client->shouldReceive('request')
            ->withAnyArgs()
            ->andReturn($response);

        return $client;
    }
}