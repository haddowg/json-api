<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Scope\ScopeTableBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopedActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopedTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A collection-scope action taking its mount type's filters through
 * {@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresActionFilters}.
 */
#[CoversClass(ActionDeclarations::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class ActionFiltersProjectionTest extends TestCase
{
    #[Test]
    public function anActionTakingEveryFilterCarriesTheCollectionFetchParameters(): void
    {
        $document = $this->project($this->articles(new FakeActionMetadata('count', ['GET'], ActionScope::Collection, responds: [new MetaResult()], filters: true)));

        $collection = $this->filterParameters($document, '/articles', 'get');
        self::assertSame(['filter[status]', 'filter[rating]', 'filter[keywords]', 'filter[wordCount]'], \array_keys($collection));
        self::assertSame($collection, $this->filterParameters($document, '/articles/-actions/count', 'get'));
    }

    #[Test]
    public function anActionTakingASubsetCarriesOnlyThoseInTheTypesOrder(): void
    {
        $document = $this->project($this->articles(new FakeActionMetadata('archive', ['POST'], ActionScope::Collection, filters: ['keywords', 'rating'])));

        $collection = $this->filterParameters($document, '/articles', 'get');
        self::assertSame(
            ['filter[rating]' => $collection['filter[rating]'], 'filter[keywords]' => $collection['filter[keywords]']],
            $this->filterParameters($document, '/articles/-actions/archive', 'post'),
        );
    }

    #[Test]
    public function theParametersKeepTheirDeepObjectArrayExampleAndDeprecationShapes(): void
    {
        $document = $this->project($this->articles(new FakeActionMetadata('count', ['GET'], ActionScope::Collection, responds: [new MetaResult()], filters: true)));
        $parameters = $this->filterParameters($document, '/articles/-actions/count', 'get');

        self::assertSame('deepObject', $parameters['filter[rating]']['style'] ?? null);
        self::assertSame('array', $this->at($parameters, 'filter[keywords]', 'schema', 'type'));
        self::assertSame('published', $parameters['filter[status]']['example'] ?? null);
        self::assertTrue($parameters['filter[status]']['deprecated'] ?? null);
        self::assertSame('integer', $this->at($parameters, 'filter[wordCount]', 'schema', 'type'));
    }

    #[Test]
    public function anActionTakingFiltersOffersABadRequest(): void
    {
        $document = $this->project($this->articles(new FakeActionMetadata('count', ['GET'], ActionScope::Collection, responds: [new MetaResult()], filters: true)));

        self::assertIsArray($this->at($document, 'paths', '/articles/-actions/count', 'get', 'responses', '400'));
    }

    #[Test]
    public function anActionWithoutTheOptInIsUnchanged(): void
    {
        $action = new FakeActionMetadata('count', ['GET'], ActionScope::Collection, responds: [new MetaResult()]);
        $withFilters = $this->project($this->articles($action));
        $withoutFilters = $this->project(FakeTypeMetadata::resource(type: 'articles', fields: $this->fields(), actions: [$action]));

        self::assertSame([], $this->filterParameters($withFilters, '/articles/-actions/count', 'get'));
        self::assertSame(
            $this->at($withoutFilters, 'paths', '/articles/-actions/count'),
            $this->at($withFilters, 'paths', '/articles/-actions/count'),
        );
    }

    #[Test]
    public function theResolverFollowsTheTypesDeclarationOrder(): void
    {
        $type = $this->articles();
        $keys = static fn(array $filters): array => \array_map(static fn(FilterInterface $filter): string => $filter->key(), $filters);

        self::assertSame(['status', 'rating', 'keywords', 'wordCount'], $keys(ActionDeclarations::filters($type, new FakeActionMetadata('a', scope: ActionScope::Collection, filters: true))));
        self::assertSame(['status', 'wordCount'], $keys(ActionDeclarations::filters($type, new FakeActionMetadata('a', scope: ActionScope::Collection, filters: ['wordCount', 'status']))));
        self::assertSame([], ActionDeclarations::filters($type, new FakeActionMetadata('a', scope: ActionScope::Collection)));
        self::assertSame([], ActionDeclarations::filters($type, new FakeActionMetadata('a', scope: ActionScope::Collection, filters: [])));
        self::assertSame([], ActionDeclarations::filters($type, new FakeActionMetadata('a', scope: ActionScope::Resource, filters: true)));
    }

    #[Test]
    public function aResourceScopeActionMayNotTakeFilters(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "publish" action on type "articles" takes the type\'s filters, but it is mounted on a single resource.');

        ActionDeclarations::validate('articles', [new FakeActionMetadata('publish', scope: ActionScope::Resource, filters: true)]);
    }

    #[Test]
    public function aResourceScopeActionFailsTheProjection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"publish" action on type "articles"');

        $this->project($this->articles(new FakeActionMetadata('publish', scope: ActionScope::Resource, filters: ['status'])));
    }

    #[Test]
    public function anUnknownFilterKeyFailsTheProjection(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "archive" action on type "articles" takes the filter "author", which the type does not declare.');

        $this->project($this->articles(new FakeActionMetadata('archive', scope: ActionScope::Collection, filters: ['status', 'author'])));
    }

    #[Test]
    public function filterKeysAreCheckedOnlyWhenGiven(): void
    {
        $action = new FakeActionMetadata('archive', scope: ActionScope::Collection, inputMode: ActionInputMode::None, filters: ['author']);
        ActionDeclarations::validate('articles', [$action]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('the type declares no filters');
        ActionDeclarations::validate('articles', [$action], []);
    }

    #[Test]
    public function aDocumentWithAFilteredActionDeclaresTheActionFiltersTier(): void
    {
        $document = $this->project($this->articles(new FakeActionMetadata('archive', scope: ActionScope::Collection, filters: ['status'])));

        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::ACTION_FILTERS)], $this->at($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aDocumentWithoutAFilteredActionKeepsTheBaseline(): void
    {
        $plain = $this->project($this->articles(new FakeActionMetadata('archive', scope: ActionScope::Collection)));
        // Every filter of a type declaring none is no filter at all.
        $empty = $this->project(FakeTypeMetadata::resource(type: 'articles', fields: $this->fields(), actions: [
            new FakeActionMetadata('archive', scope: ActionScope::Collection, filters: true),
        ]));

        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->at($plain, 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->at($empty, 'info', 'x-generator'));
    }

    #[Test]
    public function aFilteredActionNeedsTheCollectionReadScopesBesideItsOwn(): void
    {
        $articles = new ScopedTypeMetadata($this->articles(
            new ScopedActionMetadata(new FakeActionMetadata('archive', ['POST'], ActionScope::Collection, filters: ['status']), ['archive', 'list']),
            new ScopedActionMetadata(new FakeActionMetadata('export', ['POST'], ActionScope::Collection), ['export']),
        ), ['fetchCollection' => ['list', 'read']]);
        $plain = new FakeServerMetadata(
            title: 'Blog API',
            version: '1.0.0',
            types: [$articles],
            securitySchemes: ['oauth2' => SecurityScheme::oauth2(new OAuthFlows(clientCredentials: new OAuthFlow([], tokenUrl: 'https://auth.example.com/token')))],
            defaultSecurity: [SecurityRequirement::scheme('oauth2')],
        );
        $server = new ScopeDeclaringServerMetadata($plain, (new ScopeTableBuilder($plain))->build(), 'oauth2');
        $document = (new OpenApiProjector())->project($server)->toArray();

        self::assertSame([['oauth2' => ['list', 'read']]], $this->at($document, 'paths', '/articles', 'get', 'security'));
        self::assertSame([['oauth2' => ['archive', 'list', 'read']]], $this->at($document, 'paths', '/articles/-actions/archive', 'post', 'security'));
        self::assertSame([['oauth2' => ['export']]], $this->at($document, 'paths', '/articles/-actions/export', 'post', 'security'));
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    private function fields(): array
    {
        return [Id::make()->build(), Str::make('title')->build(), Integer::make('wordCount')->build()];
    }

    private function articles(ActionMetadataInterface ...$actions): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'articles',
            fields: $this->fields(),
            filters: [
                Where::make('status')->describedAs('Filter by status.')->example('published')->deprecated()->build(),
                Range::make('rating')->example(['min' => 1, 'max' => 5])->build(),
                WhereIn::make('keywords')->build(),
                Where::make('wordCount')->build(),
            ],
            actions: \array_values($actions),
        );
    }

    /**
     * @return array<mixed>
     */
    private function project(FakeTypeMetadata $articles): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(title: 'Blog API', version: '1.0.0', types: [$articles]))->toArray();
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<string, array<string, mixed>> the operation's `filter[…]` parameters, keyed by name, in order
     */
    private function filterParameters(array $document, string $path, string $method): array
    {
        $parameters = [];
        $declared = $this->at($document, 'paths', $path, $method, 'parameters') ?? [];
        self::assertIsArray($declared);
        foreach ($declared as $parameter) {
            self::assertIsArray($parameter);
            $name = $parameter['name'] ?? null;
            if (\is_string($name) && \str_starts_with($name, 'filter[')) {
                /** @var array<string, mixed> $parameter */
                $parameters[$name] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * The value at `$keys`, or `null` when any of them is missing.
     *
     * @param array<mixed> $data
     */
    private function at(array $data, string ...$keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
