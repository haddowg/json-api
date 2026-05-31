<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Structural validation metadata for a {@see \haddowg\JsonApi\Resource\Field\Field}
 * or {@see \haddowg\JsonApi\Resource\Filter\Filter}.
 *
 * Constraints are **metadata only** — the core never executes them. The
 * {@see \haddowg\JsonApi\Validation\SchemaCompiler} translates the structural
 * subset to JSON Schema; framework adapters translate the full set to their
 * native validator rules. Each constraint carries a {@see Context} declaring
 * whether it applies on create requests, update requests, or both.
 */
interface Constraint
{
    /**
     * The create/update context in which this constraint applies.
     */
    public function context(): Context;
}
