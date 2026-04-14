<?php

declare(strict_types=1);

namespace QrCommunication\Withallo;

use QrCommunication\Withallo\Enums\Environment;

/**
 * Immutable runtime configuration for the SDK.
 *
 * `apiKey` is the value you pass verbatim in the `Authorization` header.
 * Withallo does NOT use `Bearer` prefix — the raw key goes straight in.
 */
final class Config
{
    public readonly Environment $environment;

    public readonly string $apiKey;

    public readonly int $timeout;

    public readonly int $connectTimeout;

    public readonly string $userAgent;

    /**
     * @param  string|Environment  $environment  Environment or string identifier (only `production` supported)
     * @param  int  $timeout  Request timeout in seconds (default 30)
     * @param  int  $connectTimeout  Connect timeout in seconds (default 10)
     * @param  string|null  $userAgent  Custom User-Agent string, defaults to `qrcommunication/withallo-sdk-php`
     */
    public function __construct(
        string $apiKey,
        string|Environment $environment = Environment::PRODUCTION,
        int $timeout = 30,
        int $connectTimeout = 10,
        ?string $userAgent = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('apiKey cannot be empty.');
        }

        $this->apiKey = $apiKey;
        $this->environment = is_string($environment)
            ? Environment::from($environment)
            : $environment;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->userAgent = $userAgent ?? 'qrcommunication/withallo-sdk-php';
    }

    /**
     * Full base URL including `/v1/api`, e.g. `https://api.withallo.com/v1/api`.
     */
    public function baseUrl(): string
    {
        return $this->environment->apiUrl().$this->environment->basePath();
    }
}
