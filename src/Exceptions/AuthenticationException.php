<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

/**
 * Raised on HTTP 401 — API key missing or invalid.
 *
 * Withallo returns: `{"code":"API_KEY_INVALID","details":null}`
 */
final class AuthenticationException extends ApiException {}
