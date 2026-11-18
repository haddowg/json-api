<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ComponentNaming;
use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationExampleProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\TypedDocumentMeta;
use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An operation or action whose document declares its top-level `meta` answers with a
 * document of its own: the shared document narrowed to a closed `meta`. Everything else keeps
 * the shared documents untouched.
 */
#[CoversClass(TypedDocumentMeta::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationExampleProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(SchemaProjector::class)]
#[CoversClass(ComponentNaming::class)]
#[CoversClass(GeneratorContract::class)]
final class TypedDocumentMetaProjectionTest extends TestCase
{
    #[Test]
    public function aCollectionNarrowsItsMetaAndKeepsCoreMembers(): void
    {
        $document = $this->project([$this->notes()]);
        $schemas = self::arr(self::arr($document['components'])['schemas']);

        self::assertSame([
            'allOf' => [
                ['$ref' => '#/components/schemas/NotesCollection'],
                [
                    'type' => 'object',
                    'properties' => ['meta' => [
                        'type' => 'object',
                        'properties' => [
                            'unread' => ['type' => 'integer', 'description' => 'How many notes are unread.', 'examples' => [3]],
                            'page' => ['type' => 'object', 'description' => 'The members of the current page, written by the server.'],
                            'total' => ['type' => 'integer', 'description' => 'The size of the whole collection, when requested with `withCount=_self_`.', 'minimum' => 0],
                        ],
                        'required' => ['unread'],
                        'additionalProperties' => false,
                    ]],
                    'required' => ['meta'],
                ],
            ],
            'x-document-meta' => true,
        ], $schemas['NotesFetchCollectionDocument']);

        $get = self::operation($document, '/notes', 'get');
        $media = self::arr(self::arr(self::arr(self::arr($get['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/NotesFetchCollectionDocument'], $media['schema']);
        self::assertSame(['unread' => 3, 'page' => ['currentPage' => 2, 'perPage' => 15, 'from' => 16, 'to' => 30]], self::arr($media['example'])['meta']);
        self::assertSame(['contract' => GeneratorContract::TYPED_DOCUMENT_META], self::arr($document['info'])['x-generator']);
    }

    #[Test]
    public function onlyOperationsReturningMembersGetADocument(): void
    {
        $schemas = self::arr(self::arr($this->project([$this->notes()])['components'])['schemas']);

        self::assertArrayHasKey('NotesFetchOneDocument', $schemas);
        self::assertArrayNotHasKey('NotesCreateDocument', $schemas);
        self::assertArrayNotHasKey('NotesUpdateDocument', $schemas);
        self::assertArrayNotHasKey('NotesDeleteDocument', $schemas);

        // An optional member alone leaves `meta` optional.
        $fetchOne = self::arr($schemas['NotesFetchOneDocument']);
        $narrowing = self::arr(self::arr($fetchOne['allOf'])[1]);
        self::assertArrayNotHasKey('required', $narrowing);
    }

    #[Test]
    public function aTypeDeclaringNothingKeepsItsDocumentsByteForByte(): void
    {
        $plain = FakeTypeMetadata::resource(type: 'notes', fields: [Id::make()->example('1')->build()]);
        $declaringNothing = FakeTypeMetadata::resource(type: 'notes', fields: [Id::make()->example('1')->build()], documentMeta: [OperationType::FetchOne->value => []]);

        $before = (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', [$plain]))->toJsonString();
        $after = (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', [$declaringNothing]))->toJsonString();

        self::assertSame($before, $after);
        self::assertStringNotContainsString('x-document-meta', $after);
        self::assertStringContainsString('"contract":' . GeneratorContract::BASELINE, $after);
    }

    #[Test]
    public function aRelatedEndpointServesTheRelatedTypesMembers(): void
    {
        $document = $this->project([$this->notes(), $this->boards()]);
        $schemas = self::arr(self::arr($document['components'])['schemas']);

        $toMany = self::arr(self::arr($schemas['BoardsNotesFetchRelatedDocument'])['allOf']);
        self::assertSame(['$ref' => '#/components/schemas/BoardsNotesRelatedCollection'], $toMany[0]);
        self::assertArrayHasKey('unread', self::arr(self::arr(self::arr(self::arr($toMany[1])['properties'])['meta'])['properties']));

        $toOne = self::arr(self::arr($schemas['BoardsPinnedFetchRelatedDocument'])['allOf']);
        self::assertSame(['$ref' => '#/components/schemas/BoardsPinnedRelatedDocument'], $toOne[0]);
        self::assertSame(['etag'], \array_keys(self::arr(self::arr(self::arr(self::arr($toOne[1])['properties'])['meta'])['properties'])));

        // The relationship endpoint serves linkage only.
        $linkage = self::operation($document, '/boards/{id}/relationships/notes', 'get');
        self::assertSame(['$ref' => '#/components/schemas/BoardsNotesRelationshipDocument'], self::arr(self::arr(self::arr(self::arr($linkage['responses'])['200'])['content'])['application/vnd.api+json'])['schema']);

        // The polymorphic relation serves no single type, so carries none.
        self::assertArrayNotHasKey('BoardsAnyFetchRelatedDocument', $schemas);
    }

    #[Test]
    public function actionsAndADeleteNarrowTheirDocuments(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'inbox-items',
            fields: [Id::make()->example('i-1')->build()],
            actions: [
                new FakeActionMetadata('mark-as-read', ['POST'], ActionScope::Collection, ActionInputMode::None, responds: [
                    new ActionResource('inbox-items', meta: [Integer::make('marked')->required()->describedAs('How many items this call marked read.')->example(12)]),
                    new ActionCreated('inbox-items', meta: [Str::make('batch')->required()->example('b-1')]),
                ]),
                new FakeActionMetadata('stats', ['GET'], ActionScope::Collection, ActionInputMode::None, responds: [
                    new MetaResult(meta: [Integer::make('count')->required()->example(4)]),
                ]),
                new FakeActionMetadata('plain', ['POST'], ActionScope::Collection, ActionInputMode::None, responds: [new MetaResult()]),
            ],
            responses: [OperationType::Delete->value => [new MetaResult(meta: [Integer::make('archived')->required()->example(1)])]],
        );
        $document = $this->project([$type]);
        $schemas = self::arr(self::arr($document['components'])['schemas']);

        self::assertSame(['$ref' => '#/components/schemas/InboxItemsDocument'], self::arr(self::arr($schemas['InboxItemsMarkAsReadActionDocument'])['allOf'])[0]);
        self::assertSame(['$ref' => '#/components/schemas/InboxItemsDocument'], self::arr(self::arr($schemas['InboxItemsMarkAsReadActionCreatedDocument'])['allOf'])[0]);
        self::assertSame(['$ref' => '#/components/schemas/MetaDocument'], self::arr(self::arr($schemas['InboxItemsStatsActionDocument'])['allOf'])[0]);
        self::assertSame(['$ref' => '#/components/schemas/MetaDocument'], self::arr(self::arr($schemas['InboxItemsDeleteDocument'])['allOf'])[0]);
        self::assertArrayNotHasKey('InboxItemsPlainActionDocument', $schemas);

        $action = self::operation($document, '/inbox-items/-actions/mark-as-read', 'post');
        $ok = self::arr(self::arr(self::arr(self::arr($action['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/InboxItemsMarkAsReadActionDocument'], $ok['schema']);
        self::assertSame(['marked' => 12], self::arr($ok['example'])['meta']);

        $stats = self::operation($document, '/inbox-items/-actions/stats', 'get');
        $statsMedia = self::arr(self::arr(self::arr(self::arr($stats['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['meta' => ['count' => 4], 'jsonapi' => ['version' => '1.1']], $statsMedia['example']);

        $plain = self::operation($document, '/inbox-items/-actions/plain', 'post');
        $plainMedia = self::arr(self::arr(self::arr(self::arr($plain['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/MetaDocument'], $plainMedia['schema']);
        self::assertArrayNotHasKey('example', $plainMedia);

        $delete = self::operation($document, '/inbox-items/{id}', 'delete');
        self::assertSame(['meta' => ['archived' => 1], 'jsonapi' => ['version' => '1.1']], self::arr(self::arr(self::arr(self::arr($delete['responses'])['200'])['content'])['application/vnd.api+json'])['example']);
    }

    #[Test]
    public function aRequiredMemberWithoutAnExampleDropsTheExample(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'notes',
            fields: [Id::make()->example('1')->build()],
            documentMeta: [OperationType::FetchOne->value => [Integer::make('unread')->required()]],
        );
        $projection = (new OpenApiProjector())->projectWithDiagnostics(new FakeServerMetadata('API', '1', [$type]));
        $document = $projection->document->toArray();

        $fetch = self::operation($document, '/notes/{id}', 'get');
        self::assertArrayNotHasKey('example', self::arr(self::arr(self::arr(self::arr($fetch['responses'])['200'])['content'])['application/vnd.api+json']));
        $missing = \array_map(static fn($miss): string => $miss->operation . ' ' . $miss->field, $projection->missingExamples);
        self::assertContains('fetchOne.notes meta.unread', $missing);
    }

    #[Test]
    public function aMemberEnumIsHoistedAsAnAttributesIs(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'notes',
            fields: [Id::make()->example('1')->build()],
            documentMeta: [OperationType::FetchOne->value => [Str::make('status')->enum(Status::class)->example('draft')]],
        );
        $schemas = self::arr(self::arr($this->project([$type])['components'])['schemas']);

        self::assertArrayHasKey('Status', $schemas);
        self::assertStringContainsString('#/components/schemas/Status', \json_encode($schemas['NotesFetchOneDocument'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function aCollectionMemberClashingWithCoreIsRefused(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'notes',
            fields: [Id::make()->build()],
            documentMeta: [OperationType::FetchCollection->value => [Integer::make('total')]],
        );

        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('The top-level meta of the "notes" FetchCollection document declares the member "total", which core writes into every collection document.');

        $this->project([$type]);
    }

    #[Test]
    public function aDocumentNameTakenByAnotherComponentIsRefused(): void
    {
        $notes = $this->notes();
        // `notes-fetch-one` takes `NotesFetchOneDocument` for its own single document.
        $clash = FakeTypeMetadata::resource(type: 'notes-fetch-one', fields: [Id::make()->build()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The document with typed meta "NotesFetchOneDocument" would take the name of another component.');

        $this->project([$notes, $clash]);
    }

    private function notes(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'notes',
            fields: [Id::make()->example('1')->build()],
            documentMeta: [
                OperationType::FetchCollection->value => [Integer::make('unread')->required()->describedAs('How many notes are unread.')->example(3)],
                OperationType::FetchOne->value => [Str::make('etag')->example('W/"1"')],
                // A delete is never asked.
                OperationType::Delete->value => [Integer::make('ignored')->required()],
            ],
        );
    }

    private function boards(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'boards',
            fields: [Id::make()->example('b-1')->build()],
            relations: [
                new FakeRelationMetadata('notes', ['notes'], true),
                new FakeRelationMetadata('pinned', ['notes'], false),
                new FakeRelationMetadata('any', ['notes', 'boards'], true),
            ],
        );
    }

    /**
     * @param list<FakeTypeMetadata> $types
     *
     * @return array<array-key, mixed>
     */
    private function project(array $types): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', $types))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private static function operation(array $document, string $path, string $method): array
    {
        return self::arr(self::arr(self::arr($document['paths'])[$path])[$method]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
