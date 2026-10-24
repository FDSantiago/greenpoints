<?php

namespace App\Integrations\SchoolRegistry\Exceptions;

use RuntimeException;

/**
 * Base class for every School Registry failure, so callers can catch them all at once.
 * `requestId` is the X-Request-Id we sent; it matches the registry's own log line.
 */
abstract class RegistryException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $requestId = null)
    {
        parent::__construct($message);
    }
}
