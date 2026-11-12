<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\OpenApi\ErrorCoverage;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\OpenApi\OperationFacts;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `relatedQuery` is documented on every operation whose runtime reads it: one rendering
 * resources of a single type that declares a relation the profile can address.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationFacts::class)]
#[CoversClass(ErrorCoverage::class)]
final class RelatedQueryProjectionTest extends TestCase
{
    private const ARTICLES = '#/components/parameters/ArticlesRelatedQuery';
    private const COMMENTS = '#/components/parameters/CommentsRelatedQuery';

    #[Test]
    public function writesAndRelatedReadsAndResourceActionsTakeIt(): void
    {
        $document = $this->project($this->server());

        self::assertContains(self::ARTICLES, $this->refs($document, '/articles', 'get'));
        self::assertContains(self::ARTICLES, $this->refs($document, '/articles/{id}', 'get'));
        self::assertContains(self::ARTICLES, $this->refs($document, '/articles', 'post'), 'a create answering with its resource');
        self::assertContains(self::ARTICLES, $this->refs($document, '/articles/{id}', 'patch'), 'an update answering with its resource');
        self::assertContains(self::ARTICLES, $this->refs($document, '/articles/{id}/-actions/publish', 'post'), 'an action answering with a resource');
        self::assertContains(self::COMMENTS, $this->refs($document, '/articles/{id}/comments', 'get'), 'a to-many related read renders comments');
        self::assertContains(self::ARTICLES, $this->refs($document, '/comments/{id}/article', 'get'), 'a to-one related read renders an article');
    }

    #[Test]
    public function operationsTheRuntimeIgnoresItOnLeaveItOut(): void
    {
        $document = $this->project($this->server());

        foreach ([
            ['/articles/{id}', 'delete'],
            ['/articles/{id}/relationships/comments', 'get'],
            ['/articles/{id}/relationships/comments', 'patch'],
            ['/articles/{id}/-actions/archive', 'post'],
            ['/articles/{id}/attachments', 'get'],
            ['/people', 'get'],
            ['/people', 'post'],
        ] as [$path, $method]) {
            self::assertSame([], $this->refs($document, $path, $method), $method . ' ' . $path);
        }
    }

    #[Test]
    public function aWriteAnsweringWithoutItsResourceLeavesItOut(): void
    {
        $document = $this->project($this->server(articles: ['responses' => [
            OperationType::Create->value => [new NoContent()],
            OperationType::Update->value => [new NoContent()],
        ]]));

        self::assertSame([], $this->refs($document, '/articles', 'post'));
        self::assertSame([], $this->refs($document, '/articles/{id}', 'patch'));
        self::assertContains(self::ARTICLES, $this->refs($document, '/articles/{id}', 'get'));
    }

    #[Test]
    public function anUnregisteredProfileLeavesItOutEverywhere(): void
    {
        $document = $this->project($this->server(profiles: []));

        self::assertArrayNotHasKey('parameters', $this->arr($document, 'components'));
        self::assertSame([], $this->refs($document, '/articles', 'get'));
        self::assertSame([], $this->refs($document, '/articles', 'post'));
    }

    #[Test]
    public function theComponentListsTheAddressableRelationsAndTheirVocabulary(): void
    {
        $document = $this->project($this->server());
        $component = $this->arr($document, 'components', 'parameters', 'ArticlesRelatedQuery');

        self::assertSame('relatedQuery', $component['name'] ?? null);
        self::assertSame('deepObject', $component['style'] ?? null);
        self::assertSame(RelationshipQueriesProfile::URI, $component['x-profile'] ?? null);

        $schema = $this->arr($component, 'schema');
        self::assertFalse($schema['additionalProperties'] ?? null);
        self::assertSame(['comments', 'author'], \array_keys($this->arr($schema, 'properties')), 'neither the polymorphic nor the write-only relation');

        $comments = $this->arr($schema, 'properties', 'comments');
        self::assertFalse($comments['additionalProperties'] ?? null);
        self::assertSame('string', $this->arr($comments, 'properties', 'sort')['type'] ?? null);
        self::assertSame('^-?(?:createdAt|score)(?:,-?(?:createdAt|score))*$', $this->arr($comments, 'properties', 'sort')['pattern'] ?? null, 'the related sorts merged with the relation\'s own');
        $filter = $this->arr($comments, 'properties', 'filter');
        self::assertFalse($filter['additionalProperties'] ?? null);
        self::assertSame(['state', 'pinned'], \array_keys($this->arr($filter, 'properties')));

        $author = $this->arr($schema, 'properties', 'author');
        self::assertSame(['filter'], \array_keys($this->arr($author, 'properties')), 'a to-one takes no sort');
        self::assertSame(['name'], \array_keys($this->arr($author, 'properties', 'filter', 'properties')));
    }

    #[Test]
    public function aTypeWhoseRelationsAreAllPolymorphicOrWriteOnlyTakesNeitherTheParameterNorItsCodes(): void
    {
        $document = $this->project($this->server());

        self::assertArrayNotHasKey('PeopleRelatedQuery', $this->arr($document, 'components', 'parameters'));
        foreach (['SORTING_UNSUPPORTED', 'SORTING_UNRECOGNIZED', 'FILTERING_UNRECOGNIZED', 'FILTER_VALUE_INVALID'] as $code) {
            self::assertNotContains($code, $this->codes($document, '/people/{id}', 'get'), $code);
        }
    }

    #[Test]
    public function theSortCodesFollowEachAddressedToManysVocabulary(): void
    {
        $document = $this->project($this->server());

        // comments sort by createdAt and score; articles' to-one author takes no sort.
        $codes = $this->codes($document, '/articles/{id}', 'get');
        self::assertContains('SORTING_UNRECOGNIZED', $codes);
        self::assertNotContains('SORTING_UNSUPPORTED', $codes, 'every addressed to-many sorts by something');
        self::assertContains('FILTERING_UNRECOGNIZED', $codes);
        self::assertContains('FILTER_VALUE_INVALID', $codes);

        // A comment's one relation is a to-one to articles, which sorts by nothing it takes.
        $codes = $this->codes($document, '/comments/{id}', 'get');
        self::assertNotContains('SORTING_UNSUPPORTED', $codes);
        self::assertNotContains('SORTING_UNRECOGNIZED', $codes);
        self::assertContains('FILTERING_UNRECOGNIZED', $codes);
        self::assertNotContains('FILTER_VALUE_INVALID', $codes, 'articles declare no filter');
    }

    /**
     * The witness reaches every projector branch, so on each of its operations the
     * parameter is documented exactly where the fact says, and the filter and sort codes
     * it causes are offered there.
     */
    #[Test]
    public function coverageAndDocumentationAgreeAcrossTheWitness(): void
    {
        $server = ContractWitnessServer::build();
        $document = $this->project($server);
        $coverage = new ErrorCoverage();
        $filterCodes = $this->covered($coverage, $server, FilterParamUnrecognized::class);
        $sortCodes = [...$this->covered($coverage, $server, SortingUnsupported::class), ...$this->covered($coverage, $server, SortParamUnrecognized::class)];

        $documented = 0;
        foreach ($server->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $operation) {
                $rendered = OperationFacts::relatedQueryType($type, $operation, $server);
                $refs = $this->refs($document, $operation->path, \strtolower($operation->method));
                $name = $type->type() . "\0" . $operation->key->name();
                if ($rendered === null) {
                    self::assertSame([], $refs, $operation->key->name());

                    continue;
                }
                ++$documented;
                self::assertSame(['#/components/parameters/' . OperationProjector::relatedQueryComponentName($rendered)], $refs, $operation->key->name());
                self::assertContains($name, $filterCodes, $operation->key->name());
                if ($this->addressesToMany($rendered)) {
                    self::assertContains($name, $sortCodes, $operation->key->name());
                }
            }
        }
        self::assertGreaterThan(4, $documented);
    }

    private function addressesToMany(TypeMetadataInterface $rendered): bool
    {
        foreach (OperationFacts::relatedQueryRelations($rendered) as $relation) {
            if ($relation->isToMany()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string<FilterParamUnrecognized|SortingUnsupported|SortParamUnrecognized> $class
     *
     * @return list<string>
     */
    private function covered(ErrorCoverage $coverage, ServerMetadataInterface $server, string $class): array
    {
        $descriptor = $class::describe();

        return $coverage->of($server, $class, $descriptor)[1] ?? [];
    }

    /**
     * `articles` with a to-many `comments`, a to-one `author`, a polymorphic `attachments`
     * and a write-only `watchers`; `comments` with a to-one `article`; `people` whose one
     * relation is write-only.
     *
     * @param array<string, mixed> $articles
     * @param list<string>         $profiles
     */
    private function server(array $articles = [], array $profiles = [RelationshipQueriesProfile::URI]): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                // @phpstan-ignore argument.type (the overrides are FakeTypeMetadata::resource()'s own named arguments)
                FakeTypeMetadata::resource(...[
                    'type' => 'articles',
                    'fields' => [Id::make()->build(), Str::make('title')->build()],
                    'relations' => [
                        new FakeRelationMetadata('comments', ['comments'], true, filters: [Where::make('pinned')->build()], sorts: [SortByField::make('score')]),
                        new FakeRelationMetadata('author', ['people'], false),
                        new FakeRelationMetadata('attachments', ['comments', 'people'], true),
                        new WriteOnlyRelationMetadata(new FakeRelationMetadata('watchers', ['people'], true)),
                    ],
                    'actions' => [
                        new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, outputType: 'articles'),
                        new FakeActionMetadata('archive', ['POST'], ActionScope::Resource),
                    ],
                    ...$articles,
                ]),
                FakeTypeMetadata::resource(
                    type: 'comments',
                    fields: [Id::make()->build(), Str::make('body')->build()],
                    relations: [new FakeRelationMetadata('article', ['articles'], false)],
                    filters: [Where::make('state')->build()],
                    sorts: [SortByField::make('createdAt')],
                ),
                FakeTypeMetadata::resource(
                    type: 'people',
                    fields: [Id::make()->build(), Str::make('name')->build()],
                    relations: [new WriteOnlyRelationMetadata(new FakeRelationMetadata('followers', ['people'], true))],
                    filters: [Where::make('name')->build()],
                ),
            ],
            profiles: $profiles,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * The `relatedQuery` references an operation's parameters carry.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function refs(array $document, string $path, string $method): array
    {
        $refs = [];
        $parameters = $this->arr($document, 'paths', $path, $method)['parameters'] ?? [];
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            $ref = $parameter['$ref'] ?? null;
            if (\is_string($ref) && \str_ends_with($ref, 'RelatedQuery')) {
                $refs[] = $ref;
            }
            self::assertNotSame('relatedQuery', $parameter['name'] ?? null, 'never inline');
        }

        return $refs;
    }

    /**
     * Every code an operation's error responses offer, across its statuses.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function codes(array $document, string $path, string $method): array
    {
        $codes = [];
        foreach ($this->arr($document, 'paths', $path, $method, 'responses') as $status => $response) {
            if ((int) $status < 400) {
                continue;
            }
            self::assertIsArray($response);
            $ref = $response['$ref'] ?? null;
            if (\is_string($ref)) {
                $response = $this->arr($document, 'components', 'responses', \substr($ref, \strlen('#/components/responses/')));
            }
            $schema = $this->arr($response, 'content', 'application/vnd.api+json', 'schema')['$ref'] ?? null;
            self::assertIsString($schema);
            foreach ($this->arr($document, 'components', 'schemas', \substr($schema, \strlen('#/components/schemas/')), 'properties', 'errors', 'items', 'anyOf') as $branch) {
                self::assertIsArray($branch);
                self::assertIsString($branch['$ref'] ?? null);
                $shape = \substr($branch['$ref'], \strlen('#/components/schemas/'));
                if ($shape === 'Error') {
                    continue;
                }
                foreach ($this->arr($document, 'components', 'schemas', $shape, 'allOf', '1', 'properties', 'code', 'anyOf') as $code) {
                    self::assertIsArray($code);
                    self::assertIsString($code['const'] ?? null);
                    $codes[] = $code['const'];
                }
            }
        }

        return $codes;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function arr(array $value, string ...$keys): array
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor, \implode(' > ', $keys));
            $cursor = $cursor[$key];
        }
        self::assertIsArray($cursor);

        return $cursor;
    }
}
