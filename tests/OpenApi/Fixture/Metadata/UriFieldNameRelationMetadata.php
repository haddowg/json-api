<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;

/**
 * Wraps a relation fixture and serves it under another URI segment, the way an adapter
 * opts its relation metadata into {@see DeclaresRelationUriFieldName}.
 */
final readonly class UriFieldNameRelationMetadata implements DeclaresRelationUriFieldName
{
    public function __construct(
        private RelationMetadataInterface $inner,
        private string $uriFieldName,
    ) {}

    public function uriFieldName(): string
    {
        return $this->uriFieldName;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function relatedTypes(): array
    {
        return $this->inner->relatedTypes();
    }

    public function isToMany(): bool
    {
        return $this->inner->isToMany();
    }

    public function description(): ?string
    {
        return $this->inner->description();
    }

    public function isIncludable(): bool
    {
        return $this->inner->isIncludable();
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->inner->exposesRelatedEndpoint();
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->inner->exposesRelationshipEndpoint();
    }

    public function allowsReplace(): bool
    {
        return $this->inner->allowsReplace();
    }

    public function allowsAdd(): bool
    {
        return $this->inner->allowsAdd();
    }

    public function allowsRemove(): bool
    {
        return $this->inner->allowsRemove();
    }

    public function isCountable(): bool
    {
        return $this->inner->isCountable();
    }

    public function securityRead(): string|bool|null
    {
        return $this->inner->securityRead();
    }

    public function securityMutate(): string|bool|null
    {
        return $this->inner->securityMutate();
    }

    public function pageSchema(): ?Schema
    {
        return $this->inner->pageSchema();
    }

    public function filters(): array
    {
        return $this->inner->filters();
    }

    public function sorts(): array
    {
        return $this->inner->sorts();
    }

    public function relatedIncludablePaths(): array
    {
        return $this->inner->relatedIncludablePaths();
    }

    public function pivotFields(): array
    {
        return $this->inner->pivotFields();
    }
}
