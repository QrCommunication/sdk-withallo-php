# Withallo SDK (PHP) — AI Instructions

> Ce fichier est automatiquement detecte par Claude Code, Cursor, Copilot et Codex.

## SDK Overview

Package PHP `qrcommunication/withallo-sdk` pour l'API Withallo (Allo).
Pattern **Resource** : `$client->webhooks->create()`, `$client->sms->send()`, etc.

## Architecture

```
WithalloClient (point d'entree)
|-- webhooks       -> Webhooks       (scope WEBHOOKS_READ_WRITE)
|-- calls          -> Calls          (scope CONVERSATIONS_READ)
|-- contacts       -> Contacts       (scopes CONTACTS_READ / CONTACTS_READ_WRITE)
|-- sms            -> Sms            (scope SMS_SEND)
|-- phoneNumbers   -> PhoneNumbers   (scope CONVERSATIONS_READ)
|-- webhookReceiver() -> WebhookReceiver (parsing + dispatch, pas d'HTTP)
```

## API Withallo — faits essentiels

- **Base URL** : `https://api.withallo.com/v1/api`
- **Auth** : header `Authorization: <API_KEY>` (**PAS de `Bearer`**, la clef est brute)
- **Erreurs standardisees** :
  - 401 `{"code":"API_KEY_INVALID"}`
  - 403 `{"code":"API_KEY_INSUFFICIENT_SCOPE","details":[{"message":"required=SCOPE_NAME","field":"scope"}]}`
  - 429 avec header `Retry-After`
- **Pagination** : `page` (0-indexed) + `size` (1..100, defaut 10). Metadonnees dans `data.metadata.pagination`.
- **Webhooks entrants** : enveloppe `{"topic": "...", "data": {...}}`. 4 topics : `CALL_RECEIVED`, `SMS_RECEIVED`, `CONTACT_CREATED`, `CONTACT_UPDATED`. **Pas de signature HMAC publiee** a ce jour.

## Instanciation

```php
use QrCommunication\Withallo\WithalloClient;

$client = new WithalloClient(apiKey: 'your-api-key');
```

Parametres optionnels : `environment` (`Environment::PRODUCTION` par defaut), `timeout`, `connectTimeout`, `userAgent`, `httpClient` (injection d'un HttpClient custom — utile pour les tests).

## Patterns d'implementation

### Creer un webhook

```php
use QrCommunication\Withallo\Enums\WebhookTopic;

$client->webhooks->create(
    alloNumber: '+1234567890',
    url: 'https://example.com/webhooks/allo',
    topics: [WebhookTopic::CALL_RECEIVED, WebhookTopic::SMS_RECEIVED],
    enabled: true,
);
```

### Recevoir un webhook

```php
$receiver = $client->webhookReceiver();
$receiver->on(WebhookTopic::CALL_RECEIVED, function ($event) {
    // $event->get('id'), $event->get('from_number'), etc.
});
$receiver->handle(file_get_contents('php://input'));
// Repondre 200 en moins de 30s
```

### Envoyer un SMS

```php
// USA / International
$client->sms->send(from: '+1234567890', to: '+0987654321', message: 'Hello');

// France : sender ID verifie requis (Alphanumeric 3-11 chars OU short code)
$client->sms->sendFrance(senderId: 'MyCompany', to: '+33612345678', message: 'Bonjour');
```

### Contacts

```php
// GET utilise le path SINGULIER /contact/{id} (convention Withallo, pas une typo)
$contact = $client->contacts->get('cnt_abc123');

// POST/PUT/SEARCH utilisent /contacts (pluriel)
$client->contacts->create(numbers: ['+15551234567'], name: 'John');
$client->contacts->update('cnt_abc123', ['last_name' => 'Smith']);
$client->contacts->search(page: 0, size: 20);

// Conversation d'un contact (historique calls + SMS)
$client->contacts->searchConversation('cnt_abc123', page: 0, size: 20);
```

## Enums

- `Environment::PRODUCTION` (seul environnement publiquement documente)
- `Scope::WEBHOOKS_READ_WRITE | CONVERSATIONS_READ | CONTACTS_READ | CONTACTS_READ_WRITE | SMS_SEND`
- `WebhookTopic::CALL_RECEIVED | SMS_RECEIVED | CONTACT_CREATED | CONTACT_UPDATED`
- `CallResult::ANSWERED | VOICEMAIL | TRANSFERRED_AI | TRANSFERRED_EXTERNAL | BLOCKED | FAILED`
- `CallType::INBOUND | OUTBOUND`
- `SmsType::SMS | MMS`
- `SmsDirection::INBOUND | OUTBOUND`

## Exceptions

```
WithalloException (RuntimeException)
|-- ApiException                   -> erreur HTTP generique (httpStatus, responseBody, getErrorCode(), getDetails())
|   |-- AuthenticationException    -> 401
|   |-- ForbiddenException         -> 403, expose requiredScopes(): list<string>
|   |-- ValidationException        -> 400/422, expose errors(): array<string,string>
|   |-- NotFoundException          -> 404
|   |-- RateLimitException         -> 429, expose retryAfterSeconds: ?int
|-- InvalidWebhookPayloadException -> payload webhook malforme
```

Chaque ApiException expose : `$e->httpStatus`, `$e->responseBody`, `$e->getErrorCode()`, `$e->getDetails()`.

## Pieges a eviter

1. **NE JAMAIS** prefixer l'API key avec `Bearer ` — Withallo attend la clef brute dans `Authorization`.
2. Le GET contact utilise `/contact/{id}` (singulier), pas `/contacts/{id}`. Convention Withallo.
3. SMS France : `sender_id` (snake_case) dans le body, pas `from`. Sender ID doit etre pre-verifie.
4. Topics webhook : `CONTACTS_READ_WRITE` n'est PAS un topic, c'est un scope. Les topics utilisent `CONTACT_CREATED` (sans S).
5. Les payloads webhook n'ont pas de signature cryptographique publique a ce jour. Utiliser URL secrete + HTTPS + whitelist IP.
6. Repondre 200 aux webhooks en moins de 30s, sinon Withallo retry et peut desactiver le webhook.
7. Pagination : `page` est **0-indexed**, pas 1-indexed. Incrementer jusqu'a `total_pages - 1`.

## Conventions de code

- PHP 8.2+ strict types
- PSR-4 : `QrCommunication\Withallo\`
- Retours types `array<string, mixed>` pour les reponses API (documentees en PHPDoc)
- Guzzle 7.8+ comme client HTTP (injectable via constructeur HttpClient pour tester)
- PHPUnit 11 + MockHandler Guzzle pour les tests sans reseau

## Tests

```bash
composer test             # Lance la suite PHPUnit
composer analyse          # PHPStan niveau 8
composer lint             # Pint (format)
composer lint-check       # Pint en mode verification
```

Pattern de test : injection d'un `HttpClient` bati avec `MockHandler` Guzzle (voir `tests/Support/MockHttpClient.php`).

## Release workflow

1. Merger une PR sur `main`
2. `release-please` cree automatiquement une PR de release avec le changelog genere a partir des messages Conventional Commits
3. Merger la PR release cree le tag `vX.Y.Z` et publie un release GitHub
4. Packagist synchronise automatiquement via le webhook GitHub
