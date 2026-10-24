<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\ProjectedTypes;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation declared write-only through {@see DeclaresWriteOnlyRelation} projects the
 * way a write-only attribute does: in the write schemas, out of every read.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(ProjectedTypes::class)]
final class WriteOnlyRelationProjectionTest extends TestCase
{
    #[Test]
    public function aWriteOnlyRelationIsInTheWriteSchemasAndOutOfTheReadOneLikeAWriteOnlyAttribute(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        // The attribute precedent.
        self::assertArrayNotHasKey('secret', $this->arrAt($schemas, 'ArticlesAttributes', 'properties'));
        self::assertArrayHasKey('secret', $this->arrAt($schemas, 'ArticlesCreateAttributes', 'properties'));

        // The relation follows it.
        self::assertSame(['author', 'followers'], \array_keys($this->arrAt($schemas, 'ArticlesResource', 'properties', 'relationships', 'properties')));
        foreach (['ArticlesCreateRequest', 'ArticlesUpdateRequest'] as $request) {
            self::assertSame(
                ['author', 'reviewer', 'watchers', 'followers'],
                \array_keys($this->arrAt($schemas, $request, 'properties', 'data', 'properties', 'relationships', 'properties')),
            );
        }

        // The relationship object and its mutation document stay for the request bodies.
        self::assertArrayHasKey('ArticlesReviewerRelationship', $schemas);
        self::assertArrayHasKey('ArticlesReviewerRelationshipDocument', $schemas);
        self::assertArrayNotHasKey('ArticlesReviewerRelatedDocument', $schemas);
    }

    #[Test]
    public function aWriteOnlyRelationIsMarkedWriteOnlyInEveryWriteBody(): void
    {
        $schemas = $this->arrAt($this->document(atomic: true), 'components', 'schemas');

        $bodies = [
            'ArticlesCreateRequest' => ['properties', 'data', 'properties', 'relationships', 'properties'],
            'ArticlesUpdateRequest' => ['properties', 'data', 'properties', 'relationships', 'properties'],
            'ArticlesAtomicAdd' => ['properties', 'relationships', 'properties'],
            'ArticlesAtomicUpdate' => ['properties', 'relationships', 'properties'],
        ];
        foreach ($bodies as $component => $path) {
            $relationships = $this->arrAt($schemas, $component, ...$path);
            foreach (['reviewer' => true, 'watchers' => true, 'author' => null, 'followers' => null] as $name => $writeOnly) {
                self::assertSame($writeOnly, $this->arrAt($relationships, $name)['writeOnly'] ?? null, $component . '.' . $name);
            }
        }
    }

    #[Test]
    public function aWriteOnlyRelationHasNoReadEndpointsAndItsMutationsAnswerNoContent(): void
    {
        $paths = $this->arrAt($this->document(), 'paths');

        self::assertArrayNotHasKey('/articles/{id}/reviewer', $paths);
        self::assertArrayNotHasKey('/articles/{id}/watchers', $paths);
        self::assertArrayHasKey('/articles/{id}/author', $paths);

        self::assertSame(['parameters', 'patch'], \array_keys($this->arrAt($paths, '/articles/{id}/relationships/reviewer')));
        self::assertSame(['parameters', 'post', 'delete', 'patch'], \array_keys($this->arrAt($paths, '/articles/{id}/relationships/watchers')));

        foreach (['patch', 'post', 'delete'] as $method) {
            $responses = $this->arrAt($paths, '/articles/{id}/relationships/watchers', $method, 'responses');
            self::assertArrayHasKey('204', $responses);
            self::assertArrayNotHasKey('200', $responses);
        }
        self::assertArrayHasKey('200', $this->arrAt($paths, '/articles/{id}/relationships/author', 'patch', 'responses'));
    }

    #[Test]
    public function aWriteOnlyRelationIsOutOfTheFieldsetAndWithCountVocabularies(): void
    {
        $parameters = $this->arrAt($this->document(), 'paths', '/articles', 'get', 'parameters');
        $byName = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            if (isset($parameter['name']) && \is_string($parameter['name'])) {
                $byName[$parameter['name']] = $parameter;
            }
        }

        self::assertSame(['title', 'author', 'followers'], $this->arrAt($byName, 'fields[articles]', 'schema', 'items', 'enum'));
        self::assertSame(['_self_', 'followers'], $this->arrAt($byName, 'withCount', 'schema', 'items', 'enum'));
    }

    #[Test]
    public function aWriteOnlyRelationAimedAtAnUnregisteredTypeIsNotARelatedType(): void
    {
        $server = $this->server(new WriteOnlyRelationMetadata(new FakeRelationMetadata('auditor', ['auditors'], false)));

        self::assertSame([], ProjectedTypes::relatedOnly($server));
        self::assertArrayHasKey('paths', (new OpenApiProjector())->project($server)->toArray());
    }

    #[Test]
    public function metadataThatOptsInButIsReadableProjectsAsBefore(): void
    {
        $plain = (new OpenApiProjector())->project($this->server(new FakeRelationMetadata('reviewer', ['people'], false)))->toArray();
        $optedIn = (new OpenApiProjector())->project($this->server(new WriteOnlyRelationMetadata(new FakeRelationMetadata('reviewer', ['people'], false), writeOnly: false)))->toArray();

        self::assertSame($plain, $optedIn);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(bool $atomic = false): array
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->build(), Str::make('secret')->writeOnly()->build()],
            relations: [
                FakeRelationMetadata::toOne('author', ['people']),
                new WriteOnlyRelationMetadata(new FakeRelationMetadata('reviewer', ['people'], false, includable: false, relatedEndpoint: false)),
                new WriteOnlyRelationMetadata(FakeRelationMetadata::toMany('watchers', ['people'])),
                FakeRelationMetadata::toMany('followers', ['people']),
            ],
            countable: true,
        );
        $people = FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()]);

        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$articles, $people],
            profiles: [CountableProfile::URI],
            atomicOperations: $atomic ? new FakeAtomicOperationsMetadata('/operations') : null,
        ))->toArray();

        // `followers` is readable, so only it reaches the read side of the fixture.
        self::assertArrayHasKey('/articles/{id}/followers', $this->arrAt($document, 'paths'));

        return $document;
    }

    private function server(FakeRelationMetadata|WriteOnlyRelationMetadata $relation): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->build(), Str::make('title')->build()], relations: [$relation]),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build()]),
            ],
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
