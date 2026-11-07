<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Exception\Fixture\ArticleLocked;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\Exception\Fixture\RivalResourceNotFound;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A code a type declares for some of its operations is offered by those operations alone:
 * each points its error response for the code's status at one narrowed to the codes every
 * operation raises plus the scoped ones, and the rest keep the shared response.
 */
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OpenApiProjector::class)]
final class ErrorScopeProjectionTest extends TestCase
{
    #[Test]
    public function aScopedCodeIsOfferedOnlyByTheOperationsItIsDeclaredFor(): void
    {
        $document = $this->project(['update' => [ArticleLocked::class]]);

        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '409', '$ref'));
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
        self::assertSame(
            '#/components/schemas/ErrorDocument409WithArticleLocked',
            $this->at($document, 'components', 'responses', 'ConflictWithArticleLocked', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function anOperationGainsTheStatusOfAScopedCodeInOrder(): void
    {
        $document = $this->project(['delete' => [ArticleLocked::class]]);

        $responses = $this->arr($document, 'paths', '/articles/{id}', 'delete', 'responses');
        self::assertSame('#/components/responses/ConflictWithArticleLocked', $this->at($responses, '409', '$ref'));
        $statuses = \array_map(\strval(...), \array_keys($responses));
        $sorted = $statuses;
        \sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $statuses);
    }

    #[Test]
    public function aStatusOnlyScopedCodesUseLeavesNoSharedResponseBehind(): void
    {
        // Nothing else answers 402, so the only 402 response is the narrowed one.
        $document = $this->project(['update' => [PaymentRequired::class]]);

        self::assertSame('#/components/responses/Status402WithPaymentRequired', $this->at($document, 'paths', '/articles/{id}', 'patch', 'responses', '402', '$ref'));
        self::assertArrayNotHasKey('Status402', $this->arr($document, 'components', 'responses'));
        self::assertSame(
            [['$ref' => '#/components/schemas/Error'], ['$ref' => '#/components/schemas/Error402PaymentRequired']],
            $this->at($document, 'components', 'schemas', 'ErrorDocument402WithPaymentRequired', 'properties', 'errors', 'items', 'anyOf'),
        );
    }

    #[Test]
    public function theWildcardScopesACodeToEveryOperationOfTheType(): void
    {
        $document = $this->project(['*' => [PaymentRequired::class]]);

        foreach (['/articles' => ['get', 'post'], '/articles/{id}' => ['get', 'patch', 'delete']] as $path => $methods) {
            foreach ($methods as $method) {
                self::assertSame('#/components/responses/Status402WithPaymentRequired', $this->at($document, 'paths', $path, $method, 'responses', '402', '$ref'), "{$method} {$path}");
            }
        }
    }

    #[Test]
    public function eachCodesExampleIsBuiltOnceAndReferencedWhereItIsOffered(): void
    {
        $document = $this->project(['update' => [ArticleLocked::class], 'delete' => [ArticleLocked::class]]);

        self::assertSame('Article locked', $this->at($document, 'components', 'examples', 'ARTICLE_LOCKED', 'summary'));
        self::assertSame(
            ['$ref' => '#/components/examples/ARTICLE_LOCKED'],
            $this->at($document, 'components', 'responses', 'ConflictWithArticleLocked', 'content', 'application/vnd.api+json', 'examples', 'ARTICLE_LOCKED'),
        );
        self::assertArrayNotHasKey('ARTICLE_LOCKED', $this->arr($document, 'components', 'responses', 'Conflict', 'content', 'application/vnd.api+json', 'examples'));
    }

    #[Test]
    public function aCodeTheServerCataloguesForEveryOperationIgnoresAScope(): void
    {
        $server = new ContributingServerMetadata($this->server(['update' => [PaymentRequired::class]]), new ClassListErrorSource(PaymentRequired::class));
        $document = (new OpenApiProjector())->project($server)->toArray();

        self::assertArrayHasKey('Error402', $this->arr($document, 'components', 'schemas'));
        self::assertArrayNotHasKey('Error402PaymentRequired', $this->arr($document, 'components', 'schemas'));
        self::assertArrayNotHasKey('402', $this->arr($document, 'paths', '/articles/{id}', 'patch', 'responses'));
    }

    #[Test]
    public function anOperationTheTypeDoesNotExposeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The type "articles" declares errors for the operation "publish"');

        $this->project(['publish' => [ArticleLocked::class]]);
    }

    #[Test]
    public function aScopedCodeClaimingACataloguedCodeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->project(['update' => [RivalResourceNotFound::class]]);
    }

    /**
     * @param array<string, list<class-string<\haddowg\JsonApi\Exception\DescribedErrorInterface>>> $operationErrors
     *
     * @return array<array-key, mixed>
     */
    private function project(array $operationErrors): array
    {
        return (new OpenApiProjector())->project($this->server($operationErrors))->toArray();
    }

    /**
     * @param array<string, list<class-string<\haddowg\JsonApi\Exception\DescribedErrorInterface>>> $operationErrors
     */
    private function server(array $operationErrors): ServerMetadataInterface
    {
        return new FakeServerMetadata(title: 'API', version: '1.0.0', types: [
            FakeTypeMetadata::resource(
                type: 'articles',
                fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()],
                operationErrors: $operationErrors,
            ),
        ]);
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
