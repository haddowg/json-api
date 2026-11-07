<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Exception\Fixture\ArticleLocked;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\Exception\Fixture\RivalResourceNotFound;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A code whose scope names some operations is offered by those operations alone: each points
 * its error response for the code's status at one narrowed to the codes every operation
 * raises plus the scoped ones, and the rest keep the shared response. The scope is the
 * descriptor's own, or one a {@see ScopedErrorSource} gives it.
 */
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(ErrorScope::class)]
#[CoversClass(ScopedErrorSource::class)]
final class ErrorScopeProjectionTest extends TestCase
{
    #[Test]
    public function aDescriptorsScopeOffersItsCodeOnlyOnTheOperationsItNames(): void
    {
        // ArticleLocked scopes itself to the article update and delete.
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($document, 'paths', '/articles/{id}', 'delete', 'responses', '409', '$ref'));
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles', 'post', 'responses', '409', '$ref'));

        $schemas = $this->arr($document, 'components', 'schemas');
        $shared = $this->arr($schemas, 'ErrorDocument409', 'properties', 'errors', 'items');
        $narrowed = $this->arr($schemas, 'ErrorDocument409WithArticleLocked', 'properties', 'errors', 'items');
        self::assertNotContains(['$ref' => '#/components/schemas/Error409ArticleLocked'], $this->arr($shared, 'anyOf'));
        self::assertSame(
            [...$this->arr($shared, 'anyOf'), ['$ref' => '#/components/schemas/Error409ArticleLocked']],
            $this->arr($narrowed, 'anyOf'),
            'the narrowed document offers the shared codes, then the scoped ones, behind the generic branch',
        );
        self::assertSame('Article locked', $this->at($schemas, 'Error409ArticleLocked', 'title'));
    }

    #[Test]
    public function anOperationGainsTheStatusOfAScopedCodeInOrder(): void
    {
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        $responses = $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses');
        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($responses, '409', '$ref'));
        $statuses = \array_map(\strval(...), \array_keys($responses));
        $sorted = $statuses;
        \sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $statuses);
    }

    #[Test]
    public function aScopedSourceScopesAnErrorItDoesNotOwn(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('articles', 'update'), PaymentRequired::class));

        self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '402', '$ref'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses'));
        // Nothing else answers 402, so no shared 402 response is left behind.
        self::assertArrayNotHasKey('PaymentRequired', $this->arr($document, 'components', 'responses'));
        self::assertSame(
            [['$ref' => '#/components/schemas/Error'], ['$ref' => '#/components/schemas/Error402PaymentRequired']],
            $this->at($document, 'components', 'schemas', 'ErrorDocument402WithPaymentRequired', 'properties', 'errors', 'items', 'anyOf'),
        );
    }

    #[Test]
    public function aScopedSourceOverridesTheDescriptorsScopeWhicheverSourceComesFirst(): void
    {
        $document = $this->project(
            new ClassListErrorSource(ArticleLocked::class),
            new ScopedErrorSource(ErrorScope::on('articles', 'create'), ArticleLocked::class),
        );

        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($document, 'paths', '/articles', 'post', 'responses', '409', '$ref'));
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
    }

    #[Test]
    public function aTypeWithNoOperationNamedScopesEveryOperationOfIt(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('articles'), PaymentRequired::class));

        foreach (['/articles' => ['get', 'post'], '/articles/{id}' => ['get', 'patch', 'delete']] as $path => $methods) {
            foreach ($methods as $method) {
                self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', $path, $method, 'responses', '402', '$ref'), "{$method} {$path}");
            }
        }
    }

    #[Test]
    public function aKindScopeCoversThatOperationOnEveryType(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::kinds(OperationKind::Delete), PaymentRequired::class));

        self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', '/articles/{id}', 'delete', 'responses', '402', '$ref'));
        self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', '/tags/{id}', 'delete', 'responses', '402', '$ref'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses'));
    }

    #[Test]
    public function anAtomicKindScopeCoversTheAtomicOperationsEndpoint(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::kinds(...OperationKind::cases()), PaymentRequired::class));

        self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', '/operations', 'post', 'responses', '402', '$ref'));
        self::assertSame('#/components/responses/PaymentRequiredWithPaymentRequired', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '402', '$ref'));
        // Every operation offers the narrowed response, so none is left on the shared one.
        self::assertArrayNotHasKey('PaymentRequired', $this->arr($document, 'components', 'responses'));
    }

    #[Test]
    public function theAtomicEndpointKeepsItsOwnWordingWhenNarrowed(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::kinds(OperationKind::Atomic), ArticleLocked::class));

        $response = $this->arr($document, 'paths', '/operations', 'post', 'responses', '409');
        self::assertSame('#/components/responses/ConflictWithArticleLocked', $response['$ref'] ?? null);
        self::assertStringContainsString('an operation conflicts', \is_string($response['description'] ?? null) ? $response['description'] : '');
        self::assertSame('#/components/responses/Conflict', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
    }

    #[Test]
    public function aTypedScopeLeavesTheAtomicEndpointAlone(): void
    {
        $document = $this->projectWithAtomic(new ScopedErrorSource(ErrorScope::kinds(OperationKind::Create), PaymentRequired::class));

        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/operations', 'post', 'responses'));
    }

    #[Test]
    public function aScopeNamingATypeTheServerDoesNotRegisterIsOfferedNowhere(): void
    {
        $document = $this->project(new ScopedErrorSource(ErrorScope::on('invoices', 'update'), PaymentRequired::class));

        self::assertArrayHasKey('Error402PaymentRequired', $this->arr($document, 'components', 'schemas'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses'));
    }

    #[Test]
    public function eachCodesExampleIsBuiltOnceAndReferencedWhereItIsOffered(): void
    {
        $document = $this->project(new ClassListErrorSource(ArticleLocked::class));

        self::assertSame('Article locked', $this->at($document, 'components', 'examples', 'ARTICLE_LOCKED', 'summary'));
        self::assertSame(
            ['$ref' => '#/components/examples/ARTICLE_LOCKED'],
            $this->at($document, 'components', 'responses', 'ConflictWithArticleLocked', 'content', 'application/vnd.api+json', 'examples', 'ARTICLE_LOCKED'),
        );
        self::assertArrayNotHasKey('ARTICLE_LOCKED', $this->arr($document, 'components', 'responses', 'Conflict', 'content', 'application/vnd.api+json', 'examples'));
    }

    #[Test]
    public function aScopeNamingSomethingThatIsNotAnOperationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names the operation "publish" of the type "articles"');

        ErrorScope::on('articles', 'publish');
    }

    #[Test]
    public function scopesCombine(): void
    {
        $scope = ErrorScope::on('articles', 'update')->and(ErrorScope::on('articles', 'delete'))->and(ErrorScope::kinds(OperationKind::Create));

        self::assertSame(['articles' => ['update', 'delete']], $scope->operations);
        self::assertSame([OperationKind::Create], $scope->kinds);
        self::assertSame(['articles' => []], ErrorScope::on('articles', 'update')->and(ErrorScope::on('articles'))->operations);
    }

    #[Test]
    public function aScopedCodeClaimingACataloguedCodeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->project(new ScopedErrorSource(ErrorScope::on('articles', 'update'), RivalResourceNotFound::class));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ErrorCatalogSourceInterface ...$sources): array
    {
        return $this->projectServer(null, ...$sources);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function projectWithAtomic(ErrorCatalogSourceInterface ...$sources): array
    {
        return $this->projectServer(new FakeAtomicOperationsMetadata(), ...$sources);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function projectServer(?FakeAtomicOperationsMetadata $atomic, ErrorCatalogSourceInterface ...$sources): array
    {
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(type: 'articles', fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()]),
            FakeTypeMetadata::resource(type: 'tags', fields: [Id::make()->example('1')->build(), Str::make('name')->example('news')->build()]),
        ], atomicOperations: $atomic);

        return (new OpenApiProjector())->project(new ContributingServerMetadata($server, ...$sources))->toArray();
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, string ...$keys): mixed
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function arr(array $value, string ...$keys): array
    {
        $found = $this->at($value, ...$keys);
        self::assertIsArray($found);

        return $found;
    }
}
