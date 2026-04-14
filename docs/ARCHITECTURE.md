# Architecture — `qrcommunication/withallo-sdk` (PHP)

This document describes the architecture of the PHP SDK in depth. For a task-oriented quick start, see the root [README](../README.md). For the raw Withallo API contract, see [openapi.yaml](./openapi.yaml).

## High-level layers

```mermaid
flowchart LR
    App[Your application] --> Client[WithalloClient]
    Client --> Resources
    Client --> Receiver[WebhookReceiver]
    Resources -->|uses| HTTP[HttpClient]
    HTTP -->|uses| Guzzle[Guzzle 7.8+]
    HTTP --> Errors[Typed exceptions]

    subgraph Resources
        W[Webhooks]
        Ca[Calls]
        Co[Contacts]
        S[Sms]
        P[PhoneNumbers]
    end
```

Key properties:

- **`WithalloClient`** is the public entry point. Constructing it builds exactly one `Config`, one `HttpClient`, and one instance of each resource. Resources share the same `HttpClient` through constructor injection.
- **Resources** are thin adapters that know *which* path / verb to call and *how* to shape the request/response array; they contain **no network logic**.
- **`HttpClient`** is the single place where Guzzle is invoked, headers are set, and HTTP error codes are translated into typed exceptions.
- **`WebhookReceiver`** is stateless and independent of `HttpClient` — parsing and dispatching incoming payloads does not need the network.

## Request lifecycle (outbound REST call)

```mermaid
sequenceDiagram
    autonumber
    participant Caller as Application
    participant R as Resource (e.g. Sms)
    participant H as HttpClient
    participant G as Guzzle Client
    participant API as api.withallo.com

    Caller->>R: $client->sms->send(from, to, message)
    R->>R: shape body (snake_case)
    R->>H: $http->post("/sms", body)
    H->>H: build URL + headers<br/>(Authorization = raw API key)
    H->>G: $guzzle->request(POST, url, opts)
    G->>API: HTTPS POST /v1/api/sms
    API-->>G: 200 { data: {...} }
    G-->>H: ResponseInterface
    H->>H: json_decode body
    H-->>R: decoded array
    R->>R: return $response['data']
    R-->>Caller: array sent SMS
```

Error path (e.g. 403):

```mermaid
sequenceDiagram
    autonumber
    participant Caller as Application
    participant R as Resource
    participant H as HttpClient
    participant G as Guzzle
    participant API as api.withallo.com

    Caller->>R: call()
    R->>H: $http->get(...)
    H->>G: request()
    G->>API: HTTPS GET ...
    API-->>G: 403 { code: API_KEY_INSUFFICIENT_SCOPE, details: [...] }
    G-->>H: Response (status 403)
    H->>H: buildException()<br/>→ new ForbiddenException(...)
    H-->>R: throw ForbiddenException
    R-->>Caller: throw ForbiddenException
    Caller->>Caller: catch & inspect $e->requiredScopes()
```

## Incoming webhook pipeline

```mermaid
sequenceDiagram
    autonumber
    participant W as Withallo
    participant Srv as Your HTTP app (Laravel/Symfony/...)
    participant Recv as WebhookReceiver
    participant Evt as WebhookEvent
    participant H as Your handler(s)

    W->>Srv: POST /webhooks/allo<br/>{ topic, data }
    Srv->>Recv: $receiver->handle($rawBody)
    Recv->>Recv: parse() — validate envelope
    Recv->>Evt: new WebhookEvent(topic, data, raw)
    Recv->>H: handlers[$event->topic->value] invoke each
    H-->>Recv: done
    Recv-->>Srv: WebhookEvent
    Srv-->>W: 200 OK (within 30s)
```

Failures in `parse()` throw `InvalidWebhookPayloadException`.

## Namespace layout

```
src/
├── WithalloClient.php       — public entry point
├── Config.php               — immutable runtime configuration
├── HttpClient.php           — Guzzle wrapper
├── Enums/
│   ├── Environment.php
│   ├── Scope.php
│   ├── WebhookTopic.php
│   ├── CallResult.php
│   ├── CallType.php
│   ├── SmsType.php
│   └── SmsDirection.php
├── Exceptions/
│   ├── WithalloException.php
│   ├── ApiException.php
│   ├── AuthenticationException.php
│   ├── ForbiddenException.php
│   ├── ValidationException.php
│   ├── NotFoundException.php
│   ├── RateLimitException.php
│   └── InvalidWebhookPayloadException.php
├── Resources/
│   ├── Resource.php         — abstract base (http injected)
│   ├── Webhooks.php
│   ├── Calls.php
│   ├── Contacts.php
│   ├── Sms.php
│   └── PhoneNumbers.php
└── Webhooks/
    ├── WebhookEvent.php
    └── WebhookReceiver.php
```

### Where the boundaries are enforced

| Layer | Depends on | Forbidden imports |
|-------|------------|-------------------|
| `Resources/*` | `HttpClient`, `Enums/*` | `WithalloClient` |
| `HttpClient` | `Config`, `Exceptions/*` | any resource |
| `Webhooks/*` | `Enums/*`, `Exceptions/*` | `HttpClient`, any resource |

## Error architecture

```mermaid
classDiagram
    class WithalloException {
        +string message
    }
    class ApiException {
        +int httpStatus
        +?array responseBody
        +getErrorCode() ?string
        +getDetails() ?array
    }
    class AuthenticationException
    class ForbiddenException {
        +requiredScopes() list
    }
    class ValidationException {
        +errors() array
    }
    class NotFoundException
    class RateLimitException {
        +?int retryAfterSeconds
    }
    class InvalidWebhookPayloadException

    RuntimeException <|-- WithalloException
    WithalloException <|-- ApiException
    ApiException <|-- AuthenticationException
    ApiException <|-- ForbiddenException
    ApiException <|-- ValidationException
    ApiException <|-- NotFoundException
    ApiException <|-- RateLimitException
    WithalloException <|-- InvalidWebhookPayloadException
```

## Extension points

| Use case | Knob |
|----------|------|
| Custom Guzzle client (logging, retry middleware) | Inject a pre-built `HttpClient` via `WithalloClient(... httpClient: ...)` |
| Mock for tests | `tests/Support/MockHttpClient` wires a Guzzle `MockHandler` and records requests |
| Custom User-Agent | `userAgent:` parameter |
| Timeouts | `timeout:` and `connectTimeout:` parameters (seconds) |

Example with Guzzle middleware (retry on 5xx + structured logging):

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use QrCommunication\Withallo\Config;
use QrCommunication\Withallo\HttpClient;
use QrCommunication\Withallo\WithalloClient;

$stack = HandlerStack::create();
$stack->push(Middleware::log($logger, new MessageFormatter('{method} {uri} -> {code}')));
$stack->push(Middleware::retry(
    decider: fn (int $retries, $req, $res = null, $err = null) =>
        $retries < 3 && ($err || ($res && $res->getStatusCode() >= 500)),
    delay: fn (int $retries) => 1000 * 2 ** $retries,
));

$guzzle = new Client(['handler' => $stack, 'timeout' => 15]);

$config = new Config(apiKey: $_ENV['WITHALLO_API_KEY']);
$httpClient = new HttpClient($config, $guzzle);

$client = new WithalloClient(
    apiKey: $_ENV['WITHALLO_API_KEY'],
    httpClient: $httpClient,
);
```

## Testing strategy

- **Unit scope**: PHPUnit 11 with Guzzle `MockHandler` queued with canned responses. Outgoing requests are recorded and asserted via `tests/Support/MockHttpClient`.
- **Static analysis**: PHPStan level 8 (`composer analyse`).
- **Format**: Laravel Pint (`composer lint` / `composer lint-check`).
- **Live smoke test**: see `docs/examples/live-smoke-test.php` (runs against a real API key).

## Security model

| Concern | Mitigation |
|---------|------------|
| API key leakage | Key lives only in `.env` / secret store — never in source, never logged. |
| Webhook spoofing | Withallo does not ship an HMAC today. Use a secret URL path + `allo_number` whitelist from `$client->phoneNumbers->list()`. |
| Dependency vulnerabilities | Only `guzzlehttp/guzzle ^7.8` at runtime. Dev deps tracked via Dependabot. |
| PHP supply chain | `composer.lock` committed and audited via `composer audit` in CI. |
