<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationExampleProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An action declaring {@see ActionCreated} documents a `201` carrying the named type's
 * resource document and an optional `Location`, and stamps its own contract tier.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationExampleProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class ActionCreatedProjectionTest extends TestCase
{
    #[Test]
    public function theCreatedDocumentIsA201WithAnOptionalLocation(): void
    {
        $responses = $this->responses($this->project([new ActionCreated('articles')]));

        self::assertArrayNotHasKey('200', $responses);
        self::assertSame('The resource the action created.', $this->at($responses, '201', 'description'));
        self::assertSame(['description' => 'The URL of the created resource.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']], $this->at($responses, '201', 'headers', 'Location'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesDocument'], $this->at($responses, '201', 'content', 'application/vnd.api+json', 'schema'));
    }

    #[Test]
    public function aCreatedDocumentSitsBesideA200Document(): void
    {
        $responses = $this->responses($this->project([new ActionResource('articles'), new ActionCreated('articles')]));

        self::assertSame(['$ref' => '#/components/schemas/ArticlesDocument'], $this->at($responses, '200', 'content', 'application/vnd.api+json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesDocument'], $this->at($responses, '201', 'content', 'application/vnd.api+json', 'schema'));
    }

    #[Test]
    public function theCreatedTypesDocumentShapeParametersAreOffered(): void
    {
        $parameters = $this->at($this->project([new ActionCreated('articles')]), 'paths', '/articles/{id}/-actions/duplicate', 'post', 'parameters');
        self::assertIsArray($parameters);

        self::assertContains('fields[articles]', \array_column($parameters, 'name'));
    }

    #[Test]
    public function theExampleIsTheCreatedResourceDocument(): void
    {
        $example = $this->at($this->responses($this->project([new ActionCreated('articles')])), '201', 'content', 'application/vnd.api+json', 'example');

        self::assertSame(['type' => 'articles', 'id' => 'art-1'], \array_intersect_key((array) $this->at($example, 'data'), ['type' => true, 'id' => true]));
    }

    #[Test]
    public function onlyADocumentWithSuchAnActionCarriesTheTier(): void
    {
        self::assertSame(GeneratorContract::toArray(actionCreated: true, examples: true), $this->at($this->project([new ActionCreated('articles')]), 'info', 'x-generator'));
        self::assertSame(GeneratorContract::toArray(examples: true), $this->at($this->project([new ActionResource('articles')]), 'info', 'x-generator'));
        self::assertSame(GeneratorContract::toArray(), $this->at($this->project([new MetaResult()]), 'info', 'x-generator'));
    }

    /**
     * @param non-empty-list<ActionResponse> $responds
     *
     * @return array<string, mixed>
     */
    private function project(array $responds): array
    {
        $type = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->example('art-1')->build(), Str::make('title')->example('Hello')->build()],
            actions: [new FakeActionMetadata('duplicate', ['POST'], ActionScope::Resource, ActionInputMode::None, responds: $responds)],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type], servers: [new \haddowg\JsonApi\OpenApi\Server('https://api.example.com')]);

        $document = \json_decode((new OpenApiProjector())->project($server)->toJsonString(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function responses(array $document): array
    {
        $responses = $this->at($document, 'paths', '/articles/{id}/-actions/duplicate', 'post', 'responses');
        self::assertIsArray($responses);

        /** @var array<string, mixed> $responses */
        return $responses;
    }

    private function at(mixed $node, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }
}
