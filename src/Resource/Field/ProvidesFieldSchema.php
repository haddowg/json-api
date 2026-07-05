<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;

/**
 * A field that describes its own OpenAPI base schema, instead of being mapped by
 * {@see SchemaProjector}'s built-in type switch. The projector consults this seam
 * first ({@see SchemaProjector::projectField()}); the field returns the base node
 * (type, properties, `oneOf`/`discriminator`, …) and the projector then layers the
 * common post-processing on top (constraints, nullable, description, example).
 *
 * It is the field-level twin of the self-describing seams already in place for
 * constraints ({@see \haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema}) and
 * filters ({@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter}): a
 * composite type — {@see Obj}, and the discriminated union built on it — carries its
 * own schema shape rather than growing a closed `instanceof` switch, so an
 * application can introduce its own composite field type and have it documented.
 *
 * A composite recurses through the passed {@see SchemaProjector} to project its
 * member/child fields, threading the {@see EnumComponentCollector} so a nested
 * enum still registers its shared component.
 */
interface ProvidesFieldSchema extends FieldInterface
{
    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema;
}
