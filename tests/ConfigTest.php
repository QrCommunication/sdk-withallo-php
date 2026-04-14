<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QrCommunication\Withallo\Config;
use QrCommunication\Withallo\Enums\Environment;

final class ConfigTest extends TestCase
{
    public function test_base_url_combines_environment_and_base_path(): void
    {
        $config = new Config(apiKey: 'key', environment: Environment::PRODUCTION);
        $this->assertSame('https://api.withallo.com/v1/api', $config->baseUrl());
    }

    public function test_rejects_empty_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config(apiKey: '   ');
    }

    public function test_accepts_string_environment(): void
    {
        $config = new Config(apiKey: 'key', environment: 'production');
        $this->assertSame(Environment::PRODUCTION, $config->environment);
    }

    public function test_user_agent_defaults_to_sdk_identifier(): void
    {
        $config = new Config(apiKey: 'key');
        $this->assertStringContainsString('withallo-sdk-php', $config->userAgent);
    }
}
