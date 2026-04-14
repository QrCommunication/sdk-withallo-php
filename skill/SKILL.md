---
name: sdk-withallo-php
description: Use when working with the Withallo (Allo) telephony/SMS/contacts API from PHP. Covers webhook CRUD + incoming webhook parsing, calls search, contacts CRUD + conversation history, SMS (US + France), and phone numbers listing. Package `qrcommunication/withallo-sdk` on Packagist.
---

# Withallo SDK (PHP) — Reference

PHP SDK for the Withallo API (formerly Allo). Covers all 5 public resources (Webhooks, Calls, Contacts, SMS, Phone Numbers) plus a stateless `WebhookReceiver` for parsing and dispatching incoming webhook payloads.

## Package

| Language | Package | Repo |
|----------|---------|------|
| PHP 8.2+ | `qrcommunication/withallo-sdk` | `QrCommunication/sdk-withallo-php` |

## Install

```bash
composer require qrcommunication/withallo-sdk
```

## Quick Start

```php
use QrCommunication\Withallo\WithalloClient;
use QrCommunication\Withallo\Enums\WebhookTopic;

$client = new WithalloClient(apiKey: getenv('WITHALLO_API_KEY'));

// Manage webhook subscriptions
$client->webhooks->create(
    alloNumber: '+1234567890',
    url: 'https://example.com/webhooks/allo',
    topics: [WebhookTopic::CALL_RECEIVED, WebhookTopic::SMS_RECEIVED],
);

// Send SMS (US / international)
$client->sms->send(from: '+1234567890', to: '+0987654321', message: 'Hello');

// Send SMS (France — verified sender ID required)
$client->sms->sendFrance(senderId: 'MyCompany', to: '+33612345678', message: 'Bonjour');

// Handle incoming webhook payloads
$receiver = $client->webhookReceiver();
$receiver->on(WebhookTopic::CALL_RECEIVED, fn ($e) => handleCall($e));
$receiver->handle(file_get_contents('php://input'));
```

## Architecture

```
WithalloClient (entry point, lazy-less: single API key)
├── webhooks       → Webhooks       (scope WEBHOOKS_READ_WRITE)
│   ├── list()                              → list<array>
│   ├── create(alloNumber, url, topics, enabled=true) → array
│   └── delete(webhookId)                   → array
├── calls          → Calls          (scope CONVERSATIONS_READ)
│   └── search(alloNumber, contactNumber?, page=0, size=10) → array
├── contacts       → Contacts       (scopes CONTACTS_READ / CONTACTS_READ_WRITE)
│   ├── get(contactId)                      → array   (GET /contact/{id} — singular)
│   ├── search(page=0, size=10)             → array   (GET /contacts)
│   ├── searchConversation(contactId, ...)  → array
│   ├── create(numbers, name?, lastName?, jobTitle?, website?, emails?, company?) → array
│   └── update(contactId, fields)           → array
├── sms            → Sms            (scope SMS_SEND)
│   ├── send(from, to, message)             → array
│   └── sendFrance(senderId, to, message)   → array
├── phoneNumbers   → PhoneNumbers   (scope CONVERSATIONS_READ)
│   └── list()                              → list<array>
└── webhookReceiver() → WebhookReceiver
    ├── on(WebhookTopic|string, callable)   → self
    ├── parse(string)                       → WebhookEvent
    ├── dispatch(WebhookEvent)              → int (handlers invoked)
    └── handle(string)                      → WebhookEvent (parse + dispatch)
```

## Authentication model

- Single auth method: raw API key in the `Authorization` header.
- **No `Bearer ` prefix.** The key is sent verbatim: `Authorization: sk_...`.
- 401 → `AuthenticationException` with body `{"code":"API_KEY_INVALID"}`.
- 403 → `ForbiddenException`, exposes `requiredScopes(): list<string>` parsed from `details[].message`.

## Webhook payloads

Envelope: `{"topic": "...", "data": { ... }}`. The SDK validates the envelope structure and hydrates a `WebhookEvent` with:

- `$event->topic` (enum)
- `$event->data` (array)
- `$event->raw` (full envelope)
- `$event->isCall() / isSms() / isContactCreated() / isContactUpdated()`
- `$event->get('path.to.field', default)` (dot notation)

**Security caveat (April 2026)**: the Withallo public docs do NOT specify an HMAC/signature header for authenticating webhook origin. Harden with HTTPS + secret URL + IP whitelist + `allo_number` matching your own numbers.

## Exceptions

```
WithalloException
├── ApiException  (httpStatus, responseBody, getErrorCode(), getDetails())
│   ├── AuthenticationException (401)
│   ├── ForbiddenException (403 — requiredScopes())
│   ├── ValidationException (400/422 — errors())
│   ├── NotFoundException (404)
│   └── RateLimitException (429 — retryAfterSeconds)
└── InvalidWebhookPayloadException
```

## Gotchas

1. **No `Bearer` prefix** on the `Authorization` header.
2. `GET /contact/{id}` is **singular**; `POST/PUT/LIST /contacts` are plural.
3. SMS France uses `sender_id` (not `from`) and the sender must be pre-verified by Allo support.
4. Pagination is **0-indexed** (`page=0` is the first page).
5. Webhook endpoints must reply 200 within 30s; Withallo will retry and potentially disable slow webhooks.
6. `CONTACTS_READ_WRITE` is a scope, not a topic. Topics use `CONTACT_CREATED` (no S).

## References

- API docs (EN): https://help.withallo.com/en/api-reference/introduction
- API docs (FR): https://help.withallo.com/fr/api-reference/introduction
- JavaScript/React SDK: https://github.com/QrCommunication/sdk-withallo-js
