<?php

declare(strict_types=1);

namespace QrCommunication\Withallo;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use QrCommunication\Withallo\Exceptions\ApiException;
use QrCommunication\Withallo\Exceptions\AuthenticationException;
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\Exceptions\NotFoundException;
use QrCommunication\Withallo\Exceptions\RateLimitException;
use QrCommunication\Withallo\Exceptions\ValidationException;

/**
 * Low-level HTTP client wrapping Guzzle.
 *
 * - Single base URL (`https://api.withallo.com/v1/api`)
 * - Single auth method: raw API key in `Authorization` header (no `Bearer` prefix)
 * - Translates Withallo HTTP errors into typed exceptions
 *
 * Never instantiate directly in application code — use WithalloClient.
 */
final class HttpClient
{
    private readonly ClientInterface $guzzle;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $guzzle = null,
    ) {
        $this->guzzle = $guzzle ?? new Client([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => $config->userAgent,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, [RequestOptions::QUERY => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, [RequestOptions::JSON => $body]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [RequestOptions::JSON => $body]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = $this->config->baseUrl().$path;

        $options[RequestOptions::HEADERS] = array_merge(
            $options[RequestOptions::HEADERS] ?? [],
            [
                'Authorization' => $this->config->apiKey,
                'Content-Type' => 'application/json',
            ],
        );

        try {
            $response = $this->guzzle->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new ApiException(
                "HTTP request failed: {$e->getMessage()}",
                0,
                null,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $decoded = $rawBody === '' ? [] : (json_decode($rawBody, true) ?? []);

        if (! is_array($decoded)) {
            $decoded = ['raw' => $rawBody];
        }

        if ($statusCode >= 400) {
            throw $this->buildException($statusCode, $decoded, $response->getHeaderLine('Retry-After'));
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildException(int $statusCode, array $body, string $retryAfter): ApiException
    {
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = $this->extractMessage($body, $statusCode);

        return match (true) {
            $statusCode === 401 => new AuthenticationException($message, $statusCode, $body),
            $statusCode === 403 => new ForbiddenException($message, $statusCode, $body),
            $statusCode === 404 => new NotFoundException($message, $statusCode, $body),
            $statusCode === 422 || $statusCode === 400 => new ValidationException($message, $statusCode, $body),
            $statusCode === 429 => $this->makeRateLimit($message, $statusCode, $body, $retryAfter),
            default => new ApiException($message, $statusCode, $body),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractMessage(array $body, int $statusCode): string
    {
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;

        if ($code !== null) {
            return $code;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return "HTTP {$statusCode}";
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function makeRateLimit(string $message, int $statusCode, array $body, string $retryAfter): RateLimitException
    {
        $ex = new RateLimitException($message, $statusCode, $body);

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            $ex->retryAfterSeconds = (int) $retryAfter;
        }

        return $ex;
    }
}
