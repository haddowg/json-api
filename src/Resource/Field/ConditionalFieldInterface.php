<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A field that says which of its access states rest on a request predicate, so code
 * reading the definitions, such as the OpenAPI error coverage, can tell a field that is
 * never read-only from one that is read-only for some requests. Every core field
 * implements it; a separate interface keeps a hand-written {@see FieldInterface}
 * implementation compiling, and such a field is read as possibly conditional.
 */
interface ConditionalFieldInterface extends FieldInterface
{
    /**
     * Whether a `readOnly(when:)` predicate decides the field's read-only state in the
     * given context. An unconditionally read-only field reports {@see isReadOnly()}.
     *
     * @param bool $creating true for a create, false for an update
     */
    public function hasReadOnlyPredicate(bool $creating): bool;

    /**
     * Whether a `writeOnly(when:)` predicate decides whether the field renders.
     */
    public function hasWriteOnlyPredicate(): bool;

    /**
     * Whether a `hidden(when:)` predicate decides whether the field renders.
     */
    public function hasHiddenPredicate(): bool;
}
