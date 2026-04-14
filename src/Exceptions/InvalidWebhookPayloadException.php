<?php

declare(strict_types=1);

namespace QrCommunication\Withallo\Exceptions;

/**
 * Raised when a webhook payload cannot be parsed or fails structural validation.
 */
final class InvalidWebhookPayloadException extends WithalloException {}
