<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Type metadata that declares OpenAPI vendor extensions (`x-…`) for single operations,
 * keyed by operation name, such as `x-feature: agency` on one related endpoint.
 *
 * It is a separate, opt-in contract beside {@see DeclaresOperationExtensions}, so a
 * hand-written implementation of that interface keeps working untouched. A type can
 * implement either or both.
 */
interface DeclaresNamedOperationExtensions extends TypeMetadataInterface
{
    /**
     * Operation name → that operation's extensions, keyed like
     * {@see DeclaresOperationExamples::operationExamples()} (see {@see OperationKey}):
     * `fetchCollection`, `fetchOne`, `create`, `update`, `delete`, and for a relation
     * `fetchRelated.<relation>`, `fetchRelationship.<relation>`,
     * `updateRelationship.<relation>`, `addRelationship.<relation>` and
     * `removeRelationship.<relation>`.
     *
     * Each set merges over the type-wide {@see DeclaresOperationExtensions::extensions()}
     * and the CRUD {@see DeclaresOperationExtensions::operationExtensions()}, and an
     * action's own extensions still win over all three. Names are normalised to the `x-`
     * prefix. A name the type does not expose fails the projection.
     *
     * @return array<string, array<string, mixed>>
     */
    public function namedOperationExtensions(): array;
}
