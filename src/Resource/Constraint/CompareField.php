<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * A cross-field rule: the value under validation must compare to **another
 * field's** value by {@see $operator} (e.g. `endDate` greater than `startDate`,
 * `passwordConfirm` equal to `password`). `$field` names the other field; the
 * operator reads `<this field> <operator> <$field>`.
 *
 * **Not round-tripped to JSON Schema** — draft 2020-12 has no cross-property
 * comparison, so the compiler skips it; a framework adapter executes it.
 */
final readonly class CompareField implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public string $field,
        public Comparison $operator,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
