<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Type metadata that declares vendor extensions, wrapped round an existing
 * implementation the way an adapter adds the seam without rewriting the metadata
 * it already builds.
 */
final readonly class ExtendedTypeMetadata implements DeclaresOperationExtensions
{
    /**
     * @param array<string, mixed>                $extensions
     * @param array<string, array<string, mixed>> $operationExtensions keyed by {@see OperationType::value}
     */
    public function __construct(
        private TypeMetadataInterface $inner,
        private array $extensions = [],
        private array $operationExtensions = [],
    ) {}

    /**
     * An `articles` type gated behind a feature flag, with a relation and a custom
     * action so every kind of operation the projector emits is present, served
     * alongside an undecorated `people` type.
     */
    public static function featureGatedServer(): FakeServerMetadata
    {
        $articles = new self(
            FakeTypeMetadata::resource(
                type: 'articles',
                fields: [Id::make()->build(), Str::make('title')->build()],
                relations: [FakeRelationMetadata::toOne('author', ['people'])],
                tags: ['Articles'],
                actions: [new FakeActionMetadata('publish')],
            ),
            extensions: ['feature' => 'agency'],
            operationExtensions: [
                OperationType::Delete->value => ['x-audit' => ['level' => 'high']],
                OperationType::Create->value => ['feature' => 'agency-write'],
            ],
        );
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()], tags: ['People']);

        return new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles, $people]);
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function operationExtensions(OperationType $operation): array
    {
        return $this->operationExtensions[$operation->value] ?? [];
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

    public function pageSchema(): ?Schema
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

    public function operationDescription(OperationType $operation): ?string
    {
        return $this->inner->operationDescription($operation);
    }

    public function responsesFor(OperationType $operation): array
    {
        return $this->inner->responsesFor($operation);
    }

    public function includablePaths(): array
    {
        return $this->inner->includablePaths();
    }
}
