<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * Thrown when two profiles are registered under the same URI.
 *
 * This is a wiring-time configuration error, not a JSON:API request error: it is
 * a {@see \LogicException} and deliberately does **not** implement
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} — it should surface as a bug
 * to fix, never as an error document in a response.
 */
final class ProfileAlreadyRegistered extends \LogicException
{
    public function __construct(public readonly string $uri)
    {
        parent::__construct("A profile is already registered for the URI '$uri'.");
    }
}
