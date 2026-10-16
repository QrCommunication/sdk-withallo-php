<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Config;
use QrCommunication\Withallo\Exceptions\ApiException;
use QrCommunication\Withallo\Exceptions\NotFoundException;
use QrCommunication\Withallo\Exceptions\RateLimitException;
use QrCommunication\Withallo\Exceptions\ValidationException;
use QrCommunication\Withallo\HttpClient;
use QrCommunication\Withallo\Tests\Support\MockHttpClient;

final class HttpClientTest extends TestCase
{
    public function test_connection_failure_preserves_the_original_guzzle_exception(): void
    {
        $failure = new ConnectException('Fixture connection failure', new Request('GET', 'https://example.invalid/contracts'));
        $handler = HandlerStack::create(new MockHandler([$failure]));
        $guzzle = new Client(['handler' => $handler, 'http_errors' => false]);
        $client = new HttpClient(new Config(apiKey: 'test-api-key'), $guzzle);

        try {
            $client->get('/contracts');
            self::fail('Expected the SDK to wrap the connection failure');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::class, $exception::class);
            self::assertSame(0, $exception->httpStatus);
            self::assertNull($exception->responseBody);
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /**
     * @param  class-string<ApiException>  $expectedClass
     */
    #[DataProvider('errorStatusProvider')]
    public function test_http_error_preserves_its_status_payload_and_public_exception_type(int $status, string $expectedClass): void
    {
        $body = ['code' => 'FIXTURE_ERROR', 'details' => [['field' => 'name', 'message' => 'Required']]];
        $mock = new MockHttpClient([new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR))]);

        try {
            $mock->client->get('/contracts');
            self::fail('Expected an SDK exception for the error response');
        } catch (ApiException $exception) {
            self::assertSame($expectedClass, $exception::class);
            self::assertSame($status, $exception->httpStatus);
            self::assertSame($body, $exception->responseBody);
            self::assertSame('FIXTURE_ERROR', $exception->getErrorCode());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function errorStatusProvider(): iterable
    {
        yield 'bad request' => [400, ValidationException::class];
        yield 'unprocessable content' => [422, ValidationException::class];
        yield 'not found' => [404, NotFoundException::class];
        yield 'server error' => [500, ApiException::class];
    }

    #[DataProvider('retryAfterProvider')]
    public function test_rate_limit_exposes_only_a_valid_retry_delay(?string $header, ?int $expectedDelay): void
    {
        $headers = $header === null ? [] : ['Retry-After' => $header];
        $body = ['code' => 'FIXTURE_RATE_LIMIT'];
        $mock = new MockHttpClient([new Response(429, $headers, json_encode($body, JSON_THROW_ON_ERROR))]);

        try {
            $mock->client->get('/contracts');
            self::fail('Expected an SDK rate-limit exception');
        } catch (RateLimitException $exception) {
            self::assertSame(429, $exception->httpStatus);
            self::assertSame($body, $exception->responseBody);
            self::assertSame($expectedDelay, $exception->retryAfterSeconds);
        }
    }

    /**
     * @return iterable<string, array{?string, ?int}>
     */
    public static function retryAfterProvider(): iterable
    {
        yield 'positive delay' => ['42', 42];
        yield 'zero delay' => ['0', 0];
        yield 'missing delay' => [null, null];
        yield 'invalid delay' => ['invalid', null];
    }
}
