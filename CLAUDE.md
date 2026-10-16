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

- Contrat consommateur PHP `^8.2`, avec strict types ; ne pas relever ce minimum pour mettre a jour les outils de developpement.
- PSR-4 : `QrCommunication\Withallo\`
- Retours types `array<string, mixed>` pour les reponses API (documentees en PHPDoc)
- Guzzle `^7.8 || ^8.0` comme client HTTP, injectable via le constructeur de `HttpClient`. Les deux majeures restent prises en charge.
- PHPUnit `^11.0 || ^12.0 || ^13.0` selon la plateforme : PHPUnit 11 sous PHP 8.2, 12 sous PHP 8.3, 13 sous PHP 8.4.1+ ; les versions exactes sont resolues par Composer.
- Composer 2.10.3 pour la recette CI. La bibliotheque ne versionne pas `composer.lock` : chaque job resout son graphe compatible, affiche les versions effectives et audite le verrou ephemere sans contourner les exigences de plateforme ou les avis de securite.

## Tests

```bash
composer test             # Lance la suite PHPUnit
composer analyse          # PHPStan niveau 8
composer lint             # Pint (format)
composer lint-check       # Pint en mode verification
```

Pattern de test : vrai `HttpClient` du SDK avec un vrai `GuzzleHttp\Client`, son `HandlerStack`, ses requetes PSR-7 et le `MockHandler` officiel comme handler terminal (voir `tests/Support/MockHttpClient.php`). La fixture recoit des reponses ; le cas `ConnectException` construit son handler dedie dans `tests/HttpClientTest.php`, sans modifier la fixture.

Ne pas appeler l'API Withallo pour la recette : aucun envoi SMS, appel telephonique ou contact reel. Ces tests traversent le client Guzzle et la conversion du SDK, mais ne prouvent pas le transport cURL/TLS/DNS reel. Les cas d'authentification brute, 401 et 403 existants restent les references ; ne pas les dupliquer.

La recette de reference `.github/workflows/tests.yml` se declenche sur push/PR `main` et via `workflow_call`. Elle couvre PHP 8.2/8.3/8.4/8.5 avec les dernieres dependances compatibles, plus une ligne PHP 8.4/Guzzle 7 distincte. Elle valide le manifeste, la plateforme et les audits global/production avant les suites. PHPUnit (fail-empty et deprecations strictes), PHPStan et Pint s'executent ensuite dans un namespace sans route externe, avec environnement limite et sans credentials. L'echec de l'isolation doit arreter la recette.

Preuve locale du 16 octobre 2026, PHP 8.4.26 : Guzzle 7.15.5/PHPUnit 11.5.57 et Guzzle 8.2.0/PHPUnit 13.4.3 passent chacun 36 tests et 91 assertions, sans echec, erreur ou skip. Les 9 nouveaux cas de `HttpClientTest` apportent 36 assertions sur les erreurs de connexion, les statuts 400/422/404/500 et `429 Retry-After`. PHPStan et Pint passent ; deux mutations privees (conversion reseau perdue, mauvais type 404) sont detectees. Le schema XML PHPUnit 11 reste accepte sans deprecation observee par PHPUnit 13.4.3.

Cette preuve locale ne vaut pas execution de toute la matrice : les jobs GitHub distants, notamment PHP 8.2/8.3/8.5, restent a confirmer apres push. Aucun resultat local ne prouve la publication Packagist.

## Release workflow

Tag-based release from the exact validated commit on `main`.

1. Add one visible, non-empty section to `CHANGELOG.md` under `## [X.Y.Z] - YYYY-MM-DD`.
   Keep the Composer library identity `qrcommunication/withallo-sdk` without a root `version` field.
2. Stage the intended release files, commit and push them. Wait for the test and quality
   workflow on that exact commit; an older green run does not validate new changes.
3. Create and push an annotated stable `vX.Y.Z` tag on that validated commit. Do not move
   a published tag. The tag, event SHA and checked-out HEAD must match exactly, and the
   commit must belong to freshly fetched `main` history.
4. `release.yml` runs the following dependency chain:
   - `validate` checks the strict stable tag, exact commit, fresh `main` ancestry and Composer identity.
   - `checks` reuses `tests.yml` at the same workflow revision. Its matrix expands to five jobs:
     latest compatible dependencies on PHP 8.2/8.3/8.4/8.5, plus PHP 8.4 with Guzzle 7.
     These jobs run dependency validation and audits, strict PHPUnit, PHPStan and Pint.
   - `release` waits for both gates, extracts exactly one visible changelog section and
     verifies the GitHub Release against the validated tag, exact target commit, notes and
     stable policy (`draft: false`, `prerelease: false`). Missing, duplicate or invisible-only
     notes must fail; generic fallback notes are not permitted.
5. GitHub Release verification proves that source identity and release metadata only.
   Packagist synchronization through the configured GitHub webhook requires separate
   public proof: check the requested package version and exact source/dist references,
   then install that public version in a fresh Composer consumer. A successful workflow
   or GitHub Release alone does not prove Packagist availability or consumer installation.

Local PHP 8.4 evidence described above is separate from execution of the full remote
matrix and public publication checks. Report each proof only after it has been obtained.
