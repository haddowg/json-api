<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;

/**
 * A **field builder** facade for an email string: presets an {@see EmailFormat}
 * constraint and builds a plain {@see Str}. Equivalent to `Str::make($name)->email()`.
 *
 * @template-covariant TValue = mixed
 *
 * @extends StrBuilder<TValue>
 */
final class Email extends StrBuilder
{
    /**
     * @return self<string>
     */
    public static function make(string $name = ''): self
    {
        return (new self($name))->email();
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
