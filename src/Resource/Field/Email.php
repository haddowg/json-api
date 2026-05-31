<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\Custom;

/**
 * A string attribute that validates email format by default. Equivalent to
 * `Str::make($name)->email()`.
 */
final class Email extends Str
{
    /**
     * @return static
     */
    public static function make(string $name): static
    {
        $field = new static($name);

        return $field->email();
    }

    /**
     * Opts into strict (RFC-compliant) email validation. Carried as adapter
     * metadata; the JSON Schema `format: email` is unaffected.
     *
     * @return static
     */
    public function strict(): static
    {
        return $this->addConstraint(new Custom('email.strict', true, $this->currentContext()));
    }
}
