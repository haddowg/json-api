<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;

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
     * Opts into strict (RFC-compliant) email validation. Reconciles to a single
     * {@see EmailFormat} carrying `strict: true` (replacing the default one added
     * by {@see make()}) — strict is typed config on the constraint, not a separate
     * rule. The JSON Schema `format: email` is unaffected.
     *
     * @return static
     */
    public function strict(): static
    {
        $this->constraints = \array_values(\array_filter(
            $this->constraints,
            static fn(ConstraintInterface $constraint): bool => !$constraint instanceof EmailFormat,
        ));

        return $this->addConstraint(new EmailFormat(true, $this->currentContext()));
    }
}
