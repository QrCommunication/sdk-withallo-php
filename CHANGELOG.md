# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.2] - 2026-10-16

### Changed

- Dependencies: `guzzlehttp/guzzle` constraint widened to `^7.8 || ^8.0` (tested on 8.2),
  `phpunit/phpunit` to `^11.0 || ^12.0 || ^13.0` (tested on 13.4; Composer keeps PHPUnit 11
  on PHP 8.2). The `php` requirement is unchanged.

- PHPUnit resolves to version 11 on PHP 8.2, 12 on PHP 8.3, and 13 on PHP 8.4.1+
  while preserving the consumer PHP `^8.2` contract. The CI recipe uses Composer 2.10.3.

### Testing

- Added nine HTTP-client cases using the real Guzzle client, handler stack and official
  MockHandler: connection exceptions preserve the original cause, HTTP 400/422/404/500
  retain their public exception contract, and HTTP 429 exposes valid `Retry-After` delays.
  Existing raw-key authentication and 401/403 tests remain unchanged.
- Verified 36 tests and 91 assertions locally on PHP 8.4.26 with both Guzzle 7.15.5 /
  PHPUnit 11.5.57 and Guzzle 8.2.0 / PHPUnit 13.4.3, with no failures, errors or skips.
  PHPUnit strict deprecation checks, PHPStan and Pint also pass.
- Tests replace only the terminal network handler and run in an isolated network
  namespace: they do not send SMS, place calls or contact the Withallo API, and do not
  claim to validate real cURL/TLS/DNS transport.

### CI/CD

- Added a reusable validation recipe targeting PHP 8.2/8.3/8.4/8.5 with the latest
  compatible dependencies and a separate PHP 8.4 / Guzzle 7 compatibility lane.
  The recipe checks actual dependency versions, Composer validation, platform
  requirements and production/development audits before isolated tests and quality checks.
- Local PHP 8.4 validation is distinct from execution of the remote CI matrix;
  it establishes neither results for the other runtimes nor public package publication.

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

[0.2.2]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.2.2
[0.2.0]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.1.0
