<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresResourceClass;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Type metadata that declares scope overrides and its resource class, wrapped round an
 * existing implementation the way an adapter adds the seams.
 */
final readonly class ScopedTypeMetadata implements DeclaresOperationScopes, DeclaresResourceClass
{
    /**
     * @param array<string, list<string>> $operationScopes
     * @param class-string|null           $resourceClass
     */
    public function __construct(
        private TypeMetadataInterface $inner,
        private array $operationScopes = [],
        private ?string $resourceClass = null,
    ) {}

    public function operationScopes(): array
    {
        return $this->operationScopes;
    }

    public function resourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function type(): string
    {
        return $this->inner->type();
    }

    public function uriType(): string
    {
        return $this->inner->uriType();
    }

    public function hasFields(): bool
    {
        return $this->inner->hasFields();
    }

    public function fields(): array
    {
        return $this->inner->fields();
    }

    public function relations(): array
    {
        return $this->inner->relations();
    }

    public function operations(): array
    {
        return $this->inner->operations();
    }

    public function securedOperations(): array
    {
        return $this->inner->securedOperations();
    }

    public function publicOperations(): array
    {
        return $this->inner->publicOperations();
    }

    public function allowsClientId(): bool
    {
        return $this->inner->allowsClientId();
    }

    public function requiresClientId(): bool
    {
        return $this->inner->requiresClientId();
    }

    public function idPattern(): ?string
    {
        return $this->inner->idPattern();
    }

    public function pageSchema(): ?\haddowg\JsonApi\OpenApi\Schema
    {
        return $this->inner->pageSchema();
    }

    public function isCountable(): bool
    {
        return $this->inner->isCountable();
    }

    public function filters(): array
    {
        return $this->inner->filters();
    }

    public function sorts(): array
    {
        return $this->inner->sorts();
    }

    public function actions(): array
    {
        return $this->inner->actions();
    }

    public function tags(): array
    {
        return $this->inner->tags();
    }

    public function description(): ?string
    {
        return $this->inner->description();
    }

    public function operationDescription(\haddowg\JsonApi\OpenApi\Metadata\OperationType $operation): ?string
    {
        return $this->inner->operationDescription($operation);
    }

    public function responsesFor(\haddowg\JsonApi\OpenApi\Metadata\OperationType $operation): array
    {
        return $this->inner->responsesFor($operation);
    }

    public function includablePaths(): array
    {
        return $this->inner->includablePaths();
    }
}
