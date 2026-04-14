<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use QrCommunication\Withallo\Config;
use QrCommunication\Withallo\Enums\Environment;
use QrCommunication\Withallo\HttpClient;

/**
 * Factory for a Guzzle-mocked HttpClient + a request log you can assert on.
 *
 * @internal
 */
final class MockHttpClient
{
    /** @var list<array{method: string, uri: string, headers: array<string, list<string>>, body: string}> */
    public array $requests = [];

    public readonly HttpClient $client;

    /**
     * @param  list<Response>  $responses
     */
    public function __construct(array $responses, string $apiKey = 'test-api-key')
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);

        $handler->push(Middleware::history($this->requests));

        $guzzle = new Client([
            'handler' => $handler,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'qrcommunication/withallo-sdk-php-test',
            ],
        ]);

        $config = new Config(apiKey: $apiKey, environment: Environment::PRODUCTION);

        $this->client = new HttpClient($config, $guzzle);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastRequestBody(): array
    {
        if ($this->requests === []) {
            return [];
        }

        $transaction = end($this->requests);
        $body = (string) $transaction['request']->getBody();

        return $body === '' ? [] : (json_decode($body, true) ?: []);
    }

    public function lastRequestMethod(): string
    {
        if ($this->requests === []) {
            return '';
        }

        $transaction = end($this->requests);

        return $transaction['request']->getMethod();
    }

    public function lastRequestUri(): string
    {
        if ($this->requests === []) {
            return '';
        }

        $transaction = end($this->requests);

        return (string) $transaction['request']->getUri();
    }

    public function lastRequestHeader(string $name): string
    {
        if ($this->requests === []) {
            return '';
        }

        $transaction = end($this->requests);

        return $transaction['request']->getHeaderLine($name);
    }
}
