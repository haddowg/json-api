<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * A {@see ConstraintInterface} that knows how to express itself as a JSON Schema
 * 2020-12 keyword — the extension point that lets a constraint *self-describe* its
 * structural meaning instead of being mapped by a central switch.
 *
 * JSON Schema is **framework-neutral**, so a constraint's schema shape is an
 * intrinsic property of the constraint (a `MinLength(3)` *is* `{"minLength": 3}`
 * everywhere), and belongs on the constraint — unlike validation *execution*,
 * which is host-specific and stays in each framework adapter's translator. Both
 * consumers of the structural schema reduce over this one method: the OpenAPI
 * {@see \haddowg\JsonApi\OpenApi\SchemaProjector} (which then layers OpenAPI-only
 * annotations — `description`/`example`, enum var-names — on top) and the
 * body-validation {@see \haddowg\JsonApi\Validation\SchemaCompiler}. A single
 * source of truth for the keyword means adding a constraint no longer means
 * editing two mirrored switches, and a consumer-defined constraint (or a native
 * escape-hatch carrier) can appear in the projected schema without a core change.
 *
 * **Contract.** {@see contribute()} receives the schema accumulated so far for the
 * owning field (its base `type`/`format`, and any earlier constraints) and returns
 * it augmented with this constraint's keyword — the {@see Schema} VO is immutable,
 * so a wither is the natural expression (`return $schema->withMinLength($this->value)`).
 * A constraint whose meaning has **no** lossless single-keyword JSON Schema form
 * (a cross-field {@see CompareField}, an opaque {@see When}, a `date-time` bound)
 * must **not** implement this interface — those degrade to a human-readable note in
 * the OpenAPI projection and are skipped by the body validator, both of which is a
 * consumer concern, not a keyword the constraint can hand back.
 *
 * The create/update {@see Context} gate is applied by the consumer *before* calling
 * this method (via {@see ConstraintInterface::context()}), so an implementation need
 * not re-check it.
 */
interface ProvidesJsonSchema extends ConstraintInterface
{
    /**
     * Returns `$schema` augmented with this constraint's JSON Schema 2020-12
     * keyword.
     */
    public function contribute(Schema $schema): Schema;
}
