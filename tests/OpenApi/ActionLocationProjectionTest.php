<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
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
 * An action declaring {@see SeeOther} answers with a URL: the projection documents the
 * `200` location document beside the `303`, and stamps its own contract tier.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class ActionLocationProjectionTest extends TestCase
{
    private const string CACHE_CONTROL = '`no-store` unless the action sets its own: the URL may be short-lived.';

    #[Test]
    public function anUndescribedActionDocumentsBothResponsesWithGeneratedWording(): void
    {
        $responses = $this->responses($this->project([new SeeOther()]));
        $ok = $this->at($responses, '200');
        self::assertIsArray($ok);
        $content = $this->at($ok, 'content', 'application/vnd.api+json');
        self::assertIsArray($content);
        unset($content['example']);
        $ok['content'] = ['application/vnd.api+json' => $content];

        self::assertSame([
            'description' => 'The URL the action produced, as its `location` link.',
            'headers' => ['Cache-Control' => ['description' => self::CACHE_CONTROL, 'schema' => ['type' => 'string']]],
            'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/LocationDocument']]],
        ], $ok);
        self::assertSame([
            'description' => 'Redirects to the URL the action produced.',
            'headers' => [
                'Location' => ['description' => 'The URL the action produced.', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uri-reference']],
                'Cache-Control' => ['description' => self::CACHE_CONTROL, 'schema' => ['type' => 'string']],
            ],
        ], $this->at($responses, '303'));
    }

    #[Test]
    public function aDeclaredMediaTypePinsTheLocationTypeAndADeclaredDescriptionReplacesTheWording(): void
    {
        $responses = $this->responses($this->project([new SeeOther('A short-lived link to the CSV.', 'text/csv')]));

        self::assertSame('A short-lived link to the CSV.', $this->at($responses, '200', 'description'));
        self::assertSame('A short-lived link to the CSV.', $this->at($responses, '303', 'description'));
        self::assertSame(['allOf' => [
            ['$ref' => '#/components/schemas/LocationDocument'],
            ['type' => 'object', 'properties' => ['links' => ['type' => 'object', 'properties' => [
                'location' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'const' => 'text/csv']]],
            ]]]],
        ]], $this->at($responses, '200', 'content', 'application/vnd.api+json', 'schema'));
    }

    #[Test]
    public function aDeclared200DocumentJoinsTheLocationDocumentInAOneOf(): void
    {
        $responses = $this->responses($this->project([new ActionResource('articles'), new SeeOther()]));

        self::assertSame(['oneOf' => [
            ['$ref' => '#/components/schemas/ArticlesDocument'],
            ['$ref' => '#/components/schemas/LocationDocument'],
        ]], $this->at($responses, '200', 'content', 'application/vnd.api+json', 'schema'));
        $headers = $this->at($responses, '200', 'headers');
        self::assertIsArray($headers);
        self::assertArrayHasKey('Cache-Control', $headers);
    }

    #[Test]
    public function theLocationComponentsDescribeTheLinkAndTheRequiredExpiry(): void
    {
        $schemas = $this->at($this->project([new SeeOther()]), 'components', 'schemas');
        self::assertIsArray($schemas);

        self::assertSame(['links', 'meta'], $this->at($schemas, 'LocationDocument', 'required'));
        self::assertSame(['location'], $this->at($schemas, 'LocationDocument', 'properties', 'links', 'required'));
        self::assertSame(['$ref' => '#/components/schemas/LocationLink'], $this->at($schemas, 'LocationDocument', 'properties', 'links', 'properties', 'location'));
        self::assertSame(['string', 'null'], $this->at($schemas, 'LocationDocument', 'properties', 'meta', 'properties', 'expiresAt', 'type'));
        self::assertSame(['expiresAt'], $this->at($schemas, 'LocationDocument', 'properties', 'meta', 'required'));
        self::assertSame(['href'], $this->at($schemas, 'LocationLink', 'required'));
        self::assertSame(['type' => 'string', 'format' => 'uri-reference'], $this->at($schemas, 'LocationLink', 'properties', 'href'));
        self::assertSame('date-time', $this->at($schemas, 'LocationLink', 'properties', 'meta', 'properties', 'expiresAt', 'format'));
    }

    #[Test]
    public function theExampleIsTheLocationDocumentAtTheActionsUrl(): void
    {
        $responses = $this->responses($this->project([new SeeOther(mediaType: 'text/csv')]));

        self::assertSame([
            'links' => [
                'self' => ['href' => 'https://api.example.com/articles/art-1/-actions/download'],
                'location' => [
                    'href' => 'https://storage.example.com/downloads/example?signature=example',
                    'type' => 'text/csv',
                    'meta' => ['expiresAt' => '2030-01-01T00:00:00+00:00'],
                ],
            ],
            'meta' => ['expiresAt' => '2030-01-01T00:00:00+00:00'],
            'jsonapi' => ['version' => '1.1'],
        ], $this->at($responses, '200', 'content', 'application/vnd.api+json', 'example'));
    }

    #[Test]
    public function anUntypedExampleOmitsTheType(): void
    {
        $responses = $this->responses($this->project([new SeeOther()]));

        $location = $this->at($responses, '200', 'content', 'application/vnd.api+json', 'example', 'links', 'location');
        self::assertIsArray($location);
        self::assertArrayNotHasKey('type', $location);
    }

    #[Test]
    public function onlyADocumentWithSuchAnActionCarriesTheTier(): void
    {
        self::assertSame(['contract' => GeneratorContract::ACTION_LOCATION], $this->at($this->project([new SeeOther()]), 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->at($this->project(null), 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], $this->at($this->project(null, fetchOne: [new Ok(), new SeeOther()]), 'info', 'x-generator'));
    }

    #[Test]
    public function aFetchOneSeeOtherIsTheUnchangedCompletionRedirect(): void
    {
        $document = $this->project(null, fetchOne: [new Ok(), new SeeOther()]);
        $responses = $this->at($document, 'paths', '/articles/{id}', 'get', 'responses');

        self::assertSame([
            'description' => 'The asynchronous operation is complete; follow Location to the produced resource.',
            'headers' => ['Location' => ['description' => 'The URL of the produced resource.', 'schema' => ['type' => 'string', 'format' => 'uri-reference']]],
        ], $this->at($responses, '303'));
        self::assertSame(['$ref' => '#/components/schemas/ArticlesDocument'], $this->at($responses, '200', 'content', 'application/vnd.api+json', 'schema'));
        $schemas = $this->at($document, 'components', 'schemas');
        self::assertIsArray($schemas);
        self::assertArrayNotHasKey('LocationDocument', $schemas);
        self::assertArrayNotHasKey('LocationLink', $schemas);
    }

    #[Test]
    public function aFetchOneSeeOtherTakesItsDeclaredDescription(): void
    {
        $document = $this->project(null, fetchOne: [new Ok(), new SeeOther('The export is ready.')]);

        self::assertSame('The export is ready.', $this->at($document, 'paths', '/articles/{id}', 'get', 'responses', '303', 'description'));
    }

    /**
     * @param non-empty-list<\haddowg\JsonApi\OpenApi\Metadata\ActionResponse>|null $responds the download action's responses, or `null` for no action
     * @param non-empty-list<OperationResponseInterface>|null            $fetchOne
     *
     * @return array<string, mixed>
     */
    private function project(?array $responds, ?array $fetchOne = null): array
    {
        $type = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->example('art-1')->build(), Str::make('title')->example('Hello')->build()],
            actions: $responds === null ? [] : [new FakeActionMetadata('download', ['GET'], ActionScope::Resource, ActionInputMode::None, responds: $responds)],
            responses: $fetchOne === null ? [] : [OperationType::FetchOne->value => $fetchOne],
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
        $responses = $this->at($document, 'paths', '/articles/{id}/-actions/download', 'get', 'responses');
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
