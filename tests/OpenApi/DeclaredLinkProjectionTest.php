<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\MissingExample;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(GeneratorContract::class)]
final class DeclaredLinkProjectionTest extends TestCase
{
    #[Test]
    public function aDeclaredLinkIsANamedOptionalPropertyOfTheResourceLinks(): void
    {
        $links = self::resourceLinks($this->project([$this->files()]));

        self::assertSame(['self', 'upload', 'thumbnail'], \array_keys(self::arr($links['properties'])));
        // A declared link is absent whenever its href resolves to null, so none is required.
        self::assertArrayNotHasKey('required', $links);
        self::assertSame(['$ref' => '#/components/schemas/LinkObject'], $links['additionalProperties']);
    }

    #[Test]
    public function aLinkWithMetaIsALinkObjectWhoseMetaHasTheDeclaredMembers(): void
    {
        $upload = self::arr(self::arr(self::resourceLinks($this->project([$this->files()]))['properties'])['upload']);

        self::assertSame('object', $upload['type']);
        self::assertSame('Where to PUT the file\'s bytes.', $upload['description']);
        self::assertSame(['href', 'title', 'meta'], $upload['required']);

        $properties = self::arr($upload['properties']);
        self::assertSame(['href', 'title', 'meta'], \array_keys($properties));
        self::assertSame(['type' => 'string', 'format' => 'uri-reference'], $properties['href']);
        self::assertSame(['type' => 'string', 'const' => 'Upload'], $properties['title']);

        $meta = self::arr($properties['meta']);
        self::assertSame(['method', 'headers', 'expiresAt', 'token'], \array_keys(self::arr($meta['properties'])));
        // A member a request may hide is not required.
        self::assertSame(['method', 'headers', 'expiresAt'], $meta['required']);

        $method = self::arr(self::arr($meta['properties'])['method']);
        self::assertSame('string', $method['type']);
        self::assertSame(['PUT'], $method['enum']);
        self::assertSame('The HTTP method to upload with.', $method['description']);
        self::assertSame('PUT', $method['example']);

        $expiresAt = self::arr(self::arr($meta['properties'])['expiresAt']);
        self::assertSame('date-time', $expiresAt['format']);
    }

    #[Test]
    public function aLinkWithoutMetaUsesTheOpenLinkUnion(): void
    {
        $thumbnail = self::arr(self::arr(self::resourceLinks($this->project([$this->files()]))['properties'])['thumbnail']);

        self::assertSame([['$ref' => '#/components/schemas/LinkObject']], $thumbnail['allOf']);
        self::assertSame('https://cdn.example.com/thumbs/f-1.png', $thumbnail['example']);
        self::assertSame('The `thumbnail` link, absent when it does not apply to the resource.', $thumbnail['description']);
    }

    #[Test]
    public function theResourceLinksExampleCarriesTheDeclaredLinks(): void
    {
        $example = self::arr(self::resourceLinks($this->project([$this->files()]))['example']);

        self::assertSame(['self', 'upload', 'thumbnail'], \array_keys($example));
        self::assertSame(
            [
                'href' => 'https://uploads.example.com/f-1?signature=abc',
                'title' => 'Upload',
                'meta' => [
                    'method' => 'PUT',
                    'headers' => ['Content-Type' => 'image/png'],
                    'expiresAt' => '2026-10-26T12:00:00+00:00',
                    'token' => 'tok-1',
                ],
            ],
            $example['upload'],
        );
    }

    #[Test]
    public function operationExamplesShowTheDeclaredLinks(): void
    {
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$this->files()]));
        $document = $projection->document->toArray();

        $fetchOne = self::arr(self::at($document, 'paths', '/files/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example'));
        $links = self::arr(self::at($fetchOne, 'data', 'links'));

        self::assertSame(['self', 'upload', 'thumbnail'], \array_keys($links));
        self::assertSame('https://cdn.example.com/thumbs/f-1.png', $links['thumbnail']);
        self::assertSame('PUT', self::at($links, 'upload', 'meta', 'method'));
        self::assertSame([], $projection->missingExamples);
    }

    #[Test]
    public function aLinkWithNoHrefExampleLeavesTheOperationWithoutOneAndSaysSo(): void
    {
        $type = $this->files([
            DeclaredLink::make('download')->hrefUsing(self::href()),
        ]);
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$type]));
        $document = $projection->document->toArray();

        self::assertNull(self::at($document, 'paths', '/files/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example'));
        $missing = \array_map(static fn(MissingExample $m): string => (string) $m, $projection->missingExamples);
        self::assertContains('fetchOne.files has no example: "files" declares no example for "links.download".', $missing);

        // The component example leaves the link out rather than inventing one.
        self::assertArrayNotHasKey('download', self::arr(self::resourceLinks($document)['example']));
    }

    #[Test]
    public function aMetaMemberWithNoExampleLeavesTheOperationWithoutOneAndSaysSo(): void
    {
        $type = $this->files([
            DeclaredLink::make('download')
                ->example('https://cdn.example.com/f-1')
                ->meta(Str::make('checksum'), Str::make('internal')->hidden())
                ->hrefUsing(self::href())
                ->metaUsing(self::meta()),
        ]);
        $projection = (new OpenApiProjector())->projectWithDiagnostics($this->server([$type]));

        $missing = \array_map(static fn(MissingExample $m): string => (string) $m, $projection->missingExamples);
        self::assertContains('fetchOne.files has no example: "files" declares no example for "links.download.meta.checksum".', $missing);
        // A hidden member never renders, so it needs no example.
        self::assertNotContains('fetchOne.files has no example: "files" declares no example for "links.download.meta.internal".', $missing);

        $properties = self::arr(self::at(self::resourceLinks($projection->document->toArray()), 'properties', 'download', 'properties', 'meta', 'properties'));
        self::assertSame(['checksum'], \array_keys($properties));
    }

    #[Test]
    public function staticMembersAreConstsAndRequired(): void
    {
        $type = $this->files([
            DeclaredLink::make('manual')
                ->rel('help')
                ->type('application/pdf')
                ->hreflang('en')
                ->describedBy('https://docs.example.com/manual')
                ->example('https://docs.example.com/manual.pdf')
                ->hrefUsing(self::href()),
        ]);
        $manual = self::arr(self::at(self::resourceLinks($this->project([$type])), 'properties', 'manual'));

        self::assertSame(['href', 'rel', 'type', 'hreflang', 'describedby'], $manual['required']);
        self::assertSame(['type' => 'string', 'const' => 'application/pdf'], self::at($manual, 'properties', 'type'));
        self::assertArrayNotHasKey('meta', self::arr($manual['properties']));
        self::assertSame(
            ['href' => 'https://docs.example.com/manual.pdf', 'rel' => 'help', 'type' => 'application/pdf', 'hreflang' => 'en', 'describedby' => 'https://docs.example.com/manual'],
            $manual['example'],
        );
    }

    #[Test]
    public function aDeclaredLinkSharingAnActionLinksNameFailsTheProjection(): void
    {
        $type = $this->files(
            [DeclaredLink::make('publish')->hrefUsing(self::href())->example('https://x.example.com')],
            [new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, ActionInputMode::None, linkName: 'publish')],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Type "files" declares the link "publish", which its "publish" action also renders as a resource link.');

        $this->project([$type]);
    }

    #[Test]
    public function onlyADocumentWithADeclaredLinkCarriesTheDeclaredLinksContract(): void
    {
        $with = $this->project([$this->files()]);
        $without = $this->project([$this->files([])]);

        self::assertSame(['contract' => GeneratorContract::DECLARED_LINKS], self::at($with, 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($without, 'info', 'x-generator'));
        self::assertSame(GeneratorContract::CONTRACT, GeneratorContract::DECLARED_LINKS);
    }

    #[Test]
    public function aResourceBuildsItsDeclaredLinksOnceAndRefusesADuplicateName(): void
    {
        $resource = new DeclaredLinkProjectionResource([
            DeclaredLink::make('a')->hrefUsing(self::href()),
            DeclaredLink::make('b')->hrefUsing(self::href())->build(),
        ]);

        $links = $resource->allLinks();
        self::assertSame(['a', 'b'], \array_map(static fn(DeclaredLink $link): string => $link->name, $links));
        self::assertSame($links, $resource->allLinks());

        $duplicate = new DeclaredLinkProjectionResource([
            DeclaredLink::make('a')->hrefUsing(self::href()),
            DeclaredLink::make('a')->hrefUsing(self::href()),
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares the link "a" twice');
        $duplicate->allLinks();
    }

    /**
     * @param list<DeclaredLink|DeclaredLinkBuilder>|null      $links
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface> $actions
     */
    private function files(?array $links = null, array $actions = []): FakeTypeMetadata
    {
        $links ??= [
            DeclaredLink::make('upload')
                ->describedAs('Where to PUT the file\'s bytes.')
                ->title('Upload')
                ->meta(
                    Str::make('method')->in(['PUT'])->guaranteed()->describedAs('The HTTP method to upload with.')->example('PUT'),
                    ArrayHash::make('headers')->guaranteed()->describedAs('Headers the upload must send.')->example(['Content-Type' => 'image/png']),
                    DateTime::make('expiresAt')->guaranteed()->describedAs('When the URL stops working.')->example('2026-10-26T12:00:00+00:00'),
                    Str::make('token')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => false)->example('tok-1'),
                )
                ->hrefUsing(self::href())
                ->metaUsing(self::meta())
                ->example('https://uploads.example.com/f-1?signature=abc'),
            DeclaredLink::make('thumbnail')
                ->hrefUsing(self::href())
                ->example('https://cdn.example.com/thumbs/f-1.png'),
        ];

        return FakeTypeMetadata::resource(
            'files',
            [Id::make()->example('f-1')->build(), Str::make('name')->example('cover.png')->build()],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
            actions: $actions,
            declaredLinks: \array_map(static fn(DeclaredLink|DeclaredLinkBuilder $link): DeclaredLink => $link instanceof DeclaredLinkBuilder ? $link->build() : $link, $links),
        );
    }

    /**
     * @return \Closure(mixed, JsonApiRequestInterface): ?string
     */
    private static function href(): \Closure
    {
        return static fn(mixed $model, JsonApiRequestInterface $request): ?string => null;
    }

    /**
     * @return \Closure(mixed, JsonApiRequestInterface): array<string, mixed>
     */
    private static function meta(): \Closure
    {
        return static fn(mixed $model, JsonApiRequestInterface $request): array => [];
    }

    /**
     * @param list<TypeMetadataInterface> $types
     */
    private function server(array $types): FakeServerMetadata
    {
        return new FakeServerMetadata('Files', '1', $types, servers: [new Server('https://api.example.com')]);
    }

    /**
     * @param list<TypeMetadataInterface> $types
     *
     * @return array<string, mixed>
     */
    private function project(array $types): array
    {
        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project($this->server($types))->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function resourceLinks(array $document): array
    {
        return self::arr(self::at($document, 'components', 'schemas', 'FilesResourceLinks'));
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}

final class DeclaredLinkProjectionResource extends AbstractResource
{
    public static string $type = 'files';

    /**
     * @param list<DeclaredLink|DeclaredLinkBuilder> $declared
     */
    public function __construct(private readonly array $declared) {}

    public function fields(): array
    {
        return [Id::make()];
    }

    public function links(): array
    {
        return $this->declared;
    }
}
