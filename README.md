# Withallo SDK — PHP

[![Version](https://img.shields.io/badge/version-0.1.0-blue.svg)](https://github.com/QrCommunication/sdk-withallo-php/releases)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Packagist](https://img.shields.io/badge/Packagist-qrcommunication%2Fwithallo--sdk-orange.svg)](https://packagist.org/packages/qrcommunication/withallo-sdk)
[![Tests](https://github.com/QrCommunication/sdk-withallo-php/actions/workflows/tests.yml/badge.svg)](https://github.com/QrCommunication/sdk-withallo-php/actions/workflows/tests.yml)

SDK PHP complet pour l'API [Withallo (Allo)](https://help.withallo.com/en/api-reference/introduction). Couvre les 5 domaines de l'API publique : **Webhooks**, **Calls**, **Contacts**, **SMS**, **Phone Numbers**. Inclut un **WebhookReceiver** pour traiter les payloads entrants (`CALL_RECEIVED`, `SMS_RECEIVED`, `CONTACT_CREATED`, `CONTACT_UPDATED`).

**PHP 8.2+** requis. Compatible Laravel, Symfony ou tout projet PHP.

---

## Table des matières / Table of contents

- [Installation](#installation)
- [Démarrage rapide / Quick start](#démarrage-rapide--quick-start)
- [Référence des ressources / Resources reference](#référence-des-ressources--resources-reference)
  - [Webhooks — gestion](#1-webhooks--clientwebhooks)
  - [WebhookReceiver — réception](#2-webhookreceiver--réception-des-événements)
  - [Calls](#3-calls--clientcalls)
  - [Contacts](#4-contacts--clientcontacts)
  - [SMS](#5-sms--clientsms)
  - [Phone Numbers](#6-phone-numbers--clientphonenumbers)
- [Gestion d'erreurs / Error handling](#gestion-derreurs--error-handling)
- [Sécurité des webhooks / Webhook security](#sécurité-des-webhooks--webhook-security)
- [Développement / Development](#développement--development)

---

## Installation

```bash
composer require qrcommunication/withallo-sdk
```

**Prérequis / Requirements** : PHP 8.2+, extensions `json` et `curl`.

Générez votre clé API depuis [web.withallo.com/settings/api](https://web.withallo.com/settings/api).

---

## Démarrage rapide / Quick start

```php
use QrCommunication\Withallo\WithalloClient;
use QrCommunication\Withallo\Enums\WebhookTopic;

$client = new WithalloClient(apiKey: 'your-api-key');

// Créer un webhook / Create a webhook
$webhook = $client->webhooks->create(
    alloNumber: '+1234567890',
    url: 'https://example.com/webhooks/allo',
    topics: [
        WebhookTopic::CALL_RECEIVED,
        WebhookTopic::SMS_RECEIVED,
    ],
);

// Envoyer un SMS (US) / Send SMS (US)
$client->sms->send(
    from: '+1234567890',
    to: '+0987654321',
    message: 'Hello from Withallo SDK',
);

// Envoyer un SMS France (sender ID verifié requis)
$client->sms->sendFrance(
    senderId: 'MyCompany',
    to: '+33612345678',
    message: 'Bonjour depuis le SDK Withallo',
);

// Lire un contact / Get a contact
$contact = $client->contacts->get('cnt_abc123');

// Rechercher l'historique d'appels / Search calls
$result = $client->calls->search(alloNumber: '+1234567890', size: 50);

// Tester la connexion / Test connectivity
$client->testConnection(); // true | false
```

---

## Référence des ressources / Resources reference

### Architecture

```
WithalloClient
 ├─ webhooks       → Webhooks          (scope: WEBHOOKS_READ_WRITE)
 ├─ calls          → Calls             (scope: CONVERSATIONS_READ)
 ├─ contacts       → Contacts          (scope: CONTACTS_READ / CONTACTS_READ_WRITE)
 ├─ sms            → Sms               (scope: SMS_SEND)
 ├─ phoneNumbers   → PhoneNumbers      (scope: CONVERSATIONS_READ)
 └─ webhookReceiver() → WebhookReceiver (no network — parses incoming payloads)
```

---

### 1. Webhooks — `$client->webhooks`

Gère les abonnements webhook configurés sur votre compte Allo.

#### `list()` — liste les webhooks

```php
/** @return list<array<string, mixed>> */
$webhooks = $client->webhooks->list();
// [
//   ['allo_number' => '+1234567890', 'enabled' => true, 'url' => '...', 'topics' => [...]],
//   ...
// ]
```

#### `create()` — crée un webhook

```php
$webhook = $client->webhooks->create(
    alloNumber: '+1234567890',
    url: 'https://example.com/webhooks/allo',
    topics: [WebhookTopic::CALL_RECEIVED, WebhookTopic::SMS_RECEIVED],
    enabled: true, // optionnel, défaut true
);
// => ['id' => 'web-abc', 'alloNumber' => '+...', 'enabled' => true, 'url' => '...', 'topics' => [...]]
```

#### `delete()` — supprime un webhook

```php
$client->webhooks->delete('web-2NfDKEm9sF8xK3pQr1Zt');
```

---

### 2. WebhookReceiver — réception des événements

Parse les payloads entrants dans votre contrôleur et dispatch vers les handlers.

```php
use QrCommunication\Withallo\Webhooks\WebhookReceiver;
use QrCommunication\Withallo\Webhooks\WebhookEvent;
use QrCommunication\Withallo\Enums\WebhookTopic;

$receiver = new WebhookReceiver;

$receiver
    ->on(WebhookTopic::CALL_RECEIVED, function (WebhookEvent $e) {
        $callId = $e->get('id');
        $summary = $e->get('one_sentence_summary');
        // ... votre logique métier
    })
    ->on(WebhookTopic::SMS_RECEIVED, function (WebhookEvent $e) {
        $content = $e->get('content');
        $direction = $e->get('direction'); // INBOUND | OUTBOUND
    })
    ->on(WebhookTopic::CONTACT_CREATED, fn (WebhookEvent $e) => /* ... */)
    ->on(WebhookTopic::CONTACT_UPDATED, fn (WebhookEvent $e) => /* ... */);

// Dans votre endpoint HTTP (Laravel, Symfony, bare PHP, etc.)
$rawBody = file_get_contents('php://input');
$event = $receiver->handle($rawBody); // parse + dispatch
return response('', 200); // toujours répondre 200 en moins de 30s
```

**Méthodes disponibles sur `WebhookEvent`** :
- `$event->topic` → `WebhookTopic`
- `$event->data` → `array<string, mixed>` payload spécifique au topic
- `$event->raw` → `array<string, mixed>` enveloppe complète
- `$event->isCall() / isSms() / isContactCreated() / isContactUpdated()` → `bool`
- `$event->get('path.to.field', default)` → accès dot-notation

---

### 3. Calls — `$client->calls`

```php
$result = $client->calls->search(
    alloNumber: '+1234567890',    // requis
    contactNumber: '+0987654321', // optionnel
    page: 0,                      // défaut 0
    size: 10,                     // défaut 10, max 100
);

// $result['results']   → list<array>
// $result['metadata']  → ['total_pages' => X, 'current_page' => Y]
```

---

### 4. Contacts — `$client->contacts`

```php
// Lire un contact
$contact = $client->contacts->get('cnt_abc123');

// Rechercher (paginé)
$result = $client->contacts->search(page: 0, size: 20);

// Créer (numbers requis, au moins un)
$contact = $client->contacts->create(
    numbers: ['+15551234567'],
    name: 'John',
    lastName: 'Doe',
    jobTitle: 'CEO',
    website: 'https://acme.com',
    emails: ['john@acme.com'],
);

// Mettre à jour (PUT — emails/numbers remplacent l'existant)
$contact = $client->contacts->update('cnt_abc123', [
    'last_name' => 'Smith',
    'job_title' => 'CTO',
    'emails' => ['john@acme.com', 'j.smith@acme.com'],
]);

// Conversation avec un contact
$conversation = $client->contacts->searchConversation('cnt_abc123', page: 0, size: 20);
```

---

### 5. SMS — `$client->sms`

```php
// États-Unis / International
$client->sms->send(
    from: '+1234567890',  // votre numéro Allo
    to: '+0987654321',
    message: 'Hello',
);

// France (Sender ID vérifié requis)
$client->sms->sendFrance(
    senderId: 'MyCompany', // 3-11 chars, A-Z/0-9, ou shortcode
    to: '+33612345678',
    message: 'Bonjour',
);
```

> **Note France** : les opérateurs français bloquent les SMS émis depuis des numéros mobiles standards. Vous devez obtenir un **Alphanumeric Sender ID** ou un **Short Code** auprès du support Allo avant utilisation.

---

### 6. Phone Numbers — `$client->phoneNumbers`

```php
$numbers = $client->phoneNumbers->list();
// [
//   ['number' => '+1234567890', 'name' => 'Main Line', 'country' => 'US', 'is_shared_number' => false],
//   ...
// ]
```

---

## Gestion d'erreurs / Error handling

Toutes les exceptions héritent de `QrCommunication\Withallo\Exceptions\WithalloException`.

```
WithalloException (RuntimeException)
├── ApiException            → erreur HTTP générique (contient httpStatus, responseBody, getErrorCode(), getDetails())
│   ├── AuthenticationException → 401 (API_KEY_INVALID)
│   ├── ForbiddenException      → 403 (API_KEY_INSUFFICIENT_SCOPE) + requiredScopes(): list<string>
│   ├── ValidationException     → 400/422 + errors(): array<string, string>
│   ├── NotFoundException       → 404
│   └── RateLimitException      → 429 + retryAfterSeconds: ?int
└── InvalidWebhookPayloadException → payload webhook malformé (parse)
```

```php
use QrCommunication\Withallo\Exceptions\ForbiddenException;
use QrCommunication\Withallo\Exceptions\RateLimitException;

try {
    $client->webhooks->create(/* ... */);
} catch (ForbiddenException $e) {
    $missing = $e->requiredScopes(); // ['WEBHOOKS_READ_WRITE']
    // ... demander à l'utilisateur d'ajouter le scope sur sa clé
} catch (RateLimitException $e) {
    sleep($e->retryAfterSeconds ?? 1);
    // ... retry
}
```

---

## Sécurité des webhooks / Webhook security

> **Important** : au moment de la publication de ce SDK (avril 2026), la documentation publique de Withallo **ne spécifie aucun en-tête de signature HMAC** permettant de vérifier cryptographiquement l'origine des webhooks. Ce SDK valide la *forme* du payload mais ne peut pas authentifier l'expéditeur.

**Mesures de durcissement recommandées** en attendant une spécification officielle :

- Servez votre endpoint webhook **en HTTPS uniquement**.
- Utilisez une URL **secrète et non devinable** (token dans le path).
- Whitelist des **IPs egress Withallo** au niveau firewall si elles sont publiées.
- Rejettez les payloads dont le `allo_number` (ou `from_number` / `to_number`) **ne correspond pas à vos propres numéros** (récupérés via `$client->phoneNumbers->list()` au démarrage).
- Répondez `200 OK` en **moins de 30 secondes**, ou Withallo considèrera la livraison comme échouée.

Si Withallo publie un schéma de signature, une méthode `WebhookReceiver::verifySignature(string $rawBody, string $signatureHeader, string $secret)` sera ajoutée sans breaking change.

---

## Développement / Development

```bash
# Installer les dépendances
composer install

# Lancer les tests
composer test

# Analyse statique (PHPStan niveau 8)
composer analyse

# Formater le code (Laravel Pint)
composer lint
```

### Versioning et release

Ce package suit le [Semantic Versioning](https://semver.org/). Les versions sont publiées automatiquement sur Packagist via le webhook GitHub → Packagist lors de chaque tag `vX.Y.Z`. Le changelog est généré par [release-please](https://github.com/googleapis/release-please).

---

## Licence / License

[MIT](LICENSE) © 2026 [QrCommunication](https://qrcommunication.com)

---

## Liens / Links

- [Documentation API Withallo (EN)](https://help.withallo.com/en/api-reference/introduction)
- [Documentation API Withallo (FR)](https://help.withallo.com/fr/api-reference/introduction)
- [SDK JavaScript / React](https://github.com/QrCommunication/sdk-withallo-js)
- [Packagist](https://packagist.org/packages/qrcommunication/withallo-sdk)
