# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 (2026-04-14)


### Features

* initial release of withallo SDK for PHP ([64ff5f3](https://github.com/QrCommunication/sdk-withallo-php/commit/64ff5f3721c56db43ebe1935bcfd331c081c1c22))

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

[0.1.0]: https://github.com/QrCommunication/sdk-withallo-php/releases/tag/v0.1.0
