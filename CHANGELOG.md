# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-04-14

### Documentation

- Added [`docs/openapi.yaml`](docs/openapi.yaml): full OpenAPI 3.1 specification
  of the Withallo REST API and webhook payloads (every endpoint, schema, error
  shape, and security scheme documented).
- Added [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) with mermaid diagrams:
  high-level layers, outbound request lifecycle, error flow, incoming webhook
  pipeline, namespace layout, error hierarchy class diagram, extension points,
  testing strategy, and security model.
- Added [`docs/examples/`](docs/examples/):
  - `LaravelWebhookController.php` — Laravel controller for receiving webhooks (with token path guard and queue dispatch)
  - `LaravelSmsService.php` — Laravel service with rate-limit retry and broadcast helpers
  - `plain-php-webhook.php` — Framework-less endpoint with file logging
  - `error-handling.php` — Exhaustive error handling patterns (retry, scope, validation, not-found)
  - `live-smoke-test.php` — End-to-end smoke test against the real API
- Added [`llms.txt`](llms.txt) at [llmstxt.org](https://llmstxt.org) format so
  LLMs and AI agents can locate the SDK's documentation, source map, and
  related packages in one file.
- Linked all new docs from the `README.md` "Documentation & examples" table.

### CI/CD

- Replaced the `release-please` workflow with a tag-based release pipeline
  aligned to the `QrCommunication/scell-io-scell-php` pattern:
  - Trigger on `push` of tag `v*`
  - Jobs: `test` (matrix PHP 8.2/8.3/8.4 with `composer validate`, PHPStan level 8, PHPUnit) → `release` (GitHub Release with notes from CHANGELOG)
  - Packagist sync is handled by the GitHub → Packagist webhook configured on the repo.
- Removed `release-please-config.json` and `.release-please-manifest.json`.

## [0.1.0] - 2026-04-14

### Features

- Initial release: PHP SDK covering the Withallo (Allo) public API
  - `WithalloClient` entry point with raw-API-key auth (no Bearer prefix)
  - Resources: `Webhooks`, `Calls`, `Contacts`, `Sms`, `PhoneNumbers`
  - `WebhookReceiver` for parsing and dispatching incoming webhook payloads
    (topics: `CALL_RECEIVED`, `SMS_RECEIVED`, `CONTACT_CREATED`, `CONTACT_UPDATED`)
  - Typed exception hierarchy (`AuthenticationException`, `ForbiddenException`,
    `ValidationException`, `NotFoundException`, `RateLimitException`,
    `InvalidWebhookPayloadException`)
  - Backed enums for `Environment`, `Scope`, `WebhookTopic`, `CallResult`,
    `CallType`, `SmsType`, `SmsDirection`
  - Guzzle-based HttpClient with injectable client for testing
  - PHPUnit 11 test suite with MockHandler support helper

### Documentation

- Bilingual (FR/EN) README with resource reference, error handling, and security guidance
- `CLAUDE.md` / `AGENTS.md` AI-agent instructions
- `skill/SKILL.md` Claude Code skill descriptor

[0.2.0]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.1.0
