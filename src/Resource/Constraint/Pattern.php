<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * String must match a regular expression (JSON Schema `pattern`).
 *
 * The pattern is an ECMA-262 regular expression **source** without delimiters,
 * as JSON Schema requires.
 *
 * `$documentsAs` is an **OpenAPI-only** hint: the convenience numeric/boolean
 * filters validate the incoming wire **string** against a regex (a query value is
 * always textual), but the parameter semantically carries a non-string JSON type —
 * `filter[rating]=4` documents as `type: number`, not a string matching
 * `^-?[0-9]+…$`. When set, the OpenAPI projector emits that type **instead of** the
 * `pattern` keyword (a `pattern` is meaningless on a non-string schema); runtime
 * validators ignore it and keep enforcing `$regex`. `null` (the default) projects
 * the conventional string `pattern`, which is exactly what {@see contribute()}
 * returns — the projector overlays the `$documentsAs` type itself.
 */
final readonly class Pattern implements ProvidesJsonSchema
{
    public function __construct(
        public string $regex,
        public Context $context = new Context(),
        public ?string $documentsAs = null,
    ) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        return $schema->withPattern($this->regex);
    }
}
