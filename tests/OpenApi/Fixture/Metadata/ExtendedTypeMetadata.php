<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresNamedOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Type metadata that declares vendor extensions, wrapped round an existing
 * implementation.
 */
final readonly class ExtendedTypeMetadata extends DelegatingTypeMetadata implements DeclaresOperationExtensions, DeclaresNamedOperationExtensions
{
    /**
     * @param array<string, mixed>                $extensions
     * @param array<string, array<string, mixed>> $operationExtensions      keyed by {@see OperationType::value}
     * @param array<string, array<string, mixed>> $namedOperationExtensions keyed by operation name
     */
    public function __construct(
        TypeMetadataInterface $inner,
        private array $extensions = [],
        private array $operationExtensions = [],
        private array $namedOperationExtensions = [],
    ) {
        parent::__construct($inner);
    }

    /**
     * An `articles` type gated behind a feature flag, with a relation and a custom
     * action so every kind of operation the projector emits is present, served
     * alongside an undecorated `people` type.
     *
     * @param array<string, array<string, mixed>> $named
     */
    public static function featureGatedServer(array $named = []): FakeServerMetadata
    {
        $articles = new self(
            self::articles(),
            extensions: ['feature' => 'agency'],
            operationExtensions: [
                OperationType::Delete->value => ['x-audit' => ['level' => 'high']],
                OperationType::Create->value => ['feature' => 'agency-write'],
            ],
            namedOperationExtensions: $named,
        );
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()], tags: ['People']);

        return new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles, $people]);
    }

    public static function articles(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [FakeRelationMetadata::toOne('author', ['people'])],
            tags: ['Articles'],
            actions: [new FakeActionMetadata('publish')],
        );
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function operationExtensions(OperationType $operation): array
    {
        return $this->operationExtensions[$operation->value] ?? [];
    }

    public function namedOperationExtensions(): array
    {
        return $this->namedOperationExtensions;
    }
}
