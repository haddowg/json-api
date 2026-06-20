<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to expose its
 * **hidden-inclusive** relation set — a single relation looked up by name,
 * INCLUDING the hidden ones a rendered relationship lookup filters out.
 *
 * A host (and {@see \haddowg\JsonApi\Serializer\EagerLoadValidator}) reads it via
 * `instanceof DeclaresRelationsInterface` (it is NOT part of
 * {@see SerializerInterface}, so a standalone bare serializer with no field
 * inventory is tolerated: it declares no relations, so a segment that would
 * resolve onto it is left unresolved). This mirrors how the server reads
 * {@see DeclaresFieldNamesInterface} / {@see DeclaresEagerLoadsInterface}.
 *
 * The lookup is hidden-inclusive because an `on()` flattened attribute's backing
 * relation chain idiomatically names a `hidden()` "internal association" — a
 * relation that never renders as a relationship but is still eager-loaded. A
 * rendered-relationship lookup ({@see \haddowg\JsonApi\Resource\AbstractResource::relationNamed()})
 * filters hidden out; this one does not.
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements this from its field
 * inventory, so every Resource subclass satisfies the interface automatically.
 */
interface DeclaresRelationsInterface
{
    /**
     * The declared relation named `$name` on this resource type, INCLUDING hidden
     * relations, or `null` when no relation of that name is declared. Used to walk
     * an `on()` eager-load chain across types (resolve each segment to its relation,
     * then follow {@see RelationInterface::relatedTypes()} to the next type) and to
     * validate that every segment is a real, to-one relation.
     */
    public function relationNamedIncludingHidden(string $name): ?RelationInterface;
}
