<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Optional relation metadata carrying the id example a relation declares for its linkage
 * (the core relation's {@see \haddowg\JsonApi\Resource\Field\ExemplifiedRelationInterface::exampleId()}).
 *
 * The operation examples use it where the related type gives none: a related type this
 * server does not register, or one whose `Id` field declares no example.
 */
interface DeclaresRelationExampleId extends RelationMetadataInterface
{
    public function exampleId(): ?string;
}
