<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\LinkDeclaringServerMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
final class ActionLinkProjectionTest extends TestCase
{
    private const GENERIC = 'The `complete` action on this resource.';

    #[Test]
    public function anActionLinkImpliesTheLinkAndCarriesItsDescription(): void
    {
        $document = $this->project(
            [ActionLink::for('complete')->describedAs('Present while the file is PENDING_UPLOAD.')->build()],
            [self::complete()],
        );
        $links = self::resourceLinks($document);

        self::assertSame(['complete'], \array_keys(self::arr($links['properties'])));
        self::assertSame(
            ['$ref' => '#/components/schemas/RenderedLink', 'description' => 'Present while the file is PENDING_UPLOAD.'],
            self::at($links, 'properties', 'complete'),
        );
        self::assertArrayNotHasKey('required', $links);
        self::assertSame(
            ['href' => 'https://api.example.com/files/f-1/-actions/complete', 'describedby' => 'https://api.example.com/openapi.json#/paths/~1files~1%7Bid%7D~1-actions~1complete/post'],
            self::at($document, 'paths', '/files/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'links', 'complete'),
        );
    }

    #[Test]
    public function anActionLinkWithoutADescriptionKeepsTheGenericOne(): void
    {
        $complete = self::arr(self::at(self::resourceLinks($this->project([ActionLink::for('complete')->build()], [self::complete()])), 'properties', 'complete'));

        self::assertSame(self::GENERIC, $complete['description']);
    }

    #[Test]
    public function theActionLinkDescriptionWinsOverAnAsLinkAction(): void
    {
        $document = $this->project(
            [ActionLink::for('complete')->describedAs('Only for the uploader.')->build()],
            [self::complete(linkName: 'complete')],
        );
        $complete = self::arr(self::at(self::resourceLinks($document), 'properties', 'complete'));

        self::assertSame('Only for the uploader.', $complete['description']);
    }

    #[Test]
    public function anAsLinkActionWithNoActionLinkKeepsTodaysProjection(): void
    {
        $complete = self::arr(self::at(self::resourceLinks($this->project([], [self::complete(linkName: 'complete')])), 'properties', 'complete'));

        self::assertSame(self::GENERIC, $complete['description']);
    }

    #[Test]
    public function anActionWithNeitherDeclarationRendersNoLink(): void
    {
        self::assertHasNoNamedLinks($this->project([], [self::complete()]));
    }

    #[Test]
    public function theOperationExampleShowsAnImpliedActionLink(): void
    {
        $document = $this->project([ActionLink::for('complete')->build()], [self::complete()]);
        $links = self::arr(self::at($document, 'paths', '/files/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'links'));

        self::assertSame(['self', 'complete'], \array_keys($links));
    }

    #[Test]
    public function anActionLinkForAnActionThisServerLacksIsLeftOut(): void
    {
        $document = $this->project([ActionLink::for('complete')->describedAs('Pending only.')->build()], []);

        self::assertHasNoNamedLinks($document);
        $links = self::arr(self::at($document, 'paths', '/files/{id}', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'links'));
        self::assertSame(['self'], \array_keys($links));
    }

    #[Test]
    public function anActionLinkForACollectionActionIsLeftOut(): void
    {
        $document = $this->project(
            [ActionLink::for('complete')->build()],
            [new FakeActionMetadata('complete', ['POST'], ActionScope::Collection, ActionInputMode::None)],
        );

        self::assertHasNoNamedLinks($document);
    }

    #[Test]
    public function aDeclaredLinkSharingAnImpliedActionLinksNameFailsTheProjection(): void
    {
        $type = $this->files(
            [ActionLink::for('complete')->build()],
            [self::complete()],
            [DeclaredLink::make('complete')->hrefUsing(static fn(mixed $m, JsonApiRequestInterface $r): ?string => null)->example('https://x.example.com')->build()],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Type "files" declares the link "complete", which its "complete" action also renders as a resource link.');

        (new OpenApiProjector())->project($this->server($type));
    }

    #[Test]
    public function anActionLinkKeepsTheBaselineContract(): void
    {
        $document = $this->project([ActionLink::for('complete')->describedAs('Pending only.')->build()], [self::complete()]);

        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($document, 'info', 'x-generator'));
    }

    private static function complete(?string $linkName = null): FakeActionMetadata
    {
        return new FakeActionMetadata('complete', ['POST'], ActionScope::Resource, ActionInputMode::None, linkName: $linkName, example: false);
    }

    /**
     * @param list<ActionLink>              $actionLinks
     * @param list<ActionMetadataInterface> $actions
     * @param list<DeclaredLink>            $declaredLinks
     */
    private function files(array $actionLinks, array $actions, array $declaredLinks = []): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            'files',
            [Id::make()->example('f-1')->build(), Str::make('name')->example('cover.png')->build()],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
            actions: $actions,
            declaredLinks: $declaredLinks,
            actionLinks: $actionLinks,
        );
    }

    private function server(FakeTypeMetadata $type): LinkDeclaringServerMetadata
    {
        return new LinkDeclaringServerMetadata(
            new FakeServerMetadata('Files', '1', [$type], servers: [new Server('https://api.example.com')]),
            documentUrl: 'https://api.example.com/openapi.json',
        );
    }

    /**
     * @param list<ActionLink>              $actionLinks
     * @param list<ActionMetadataInterface> $actions
     *
     * @return array<string, mixed>
     */
    private function project(array $actionLinks, array $actions): array
    {
        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project($this->server($this->files($actionLinks, $actions)))->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function resourceLinks(array $document): array
    {
        $links = self::arr(self::at($document, 'components', 'schemas', 'FilesResourceLinks'));
        self::assertSame(['$ref' => '#/components/schemas/ResourceLinks'], self::at($links, 'allOf', '0'));

        return self::arr(self::at($links, 'allOf', '1'));
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function assertHasNoNamedLinks(array $document): void
    {
        self::assertArrayNotHasKey('FilesResourceLinks', self::arr(self::at($document, 'components', 'schemas')));
        self::assertSame('#/components/schemas/ResourceLinks', self::at($document, 'components', 'schemas', 'FilesResource', 'properties', 'links', '$ref'));
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
