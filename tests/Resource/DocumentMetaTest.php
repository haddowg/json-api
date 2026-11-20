<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\DeclaresActionDocumentMetaInterface;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;
use haddowg\JsonApi\Resource\DocumentMetaMismatch;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentMeta::class)]
#[CoversClass(AbstractResource::class)]
final class DocumentMetaTest extends TestCase
{
    #[Test]
    public function aResourceDeclaresNothingByDefault(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'notes';

            public function fields(): array
            {
                return [Id::make()];
            }
        };

        foreach (OperationType::cases() as $operation) {
            self::assertTrue(DocumentMeta::forOperation($resource, $operation, 'notes')->isEmpty());
        }
    }

    #[Test]
    public function aComposedTypeDeclaresThroughTheInterface(): void
    {
        $declarer = $this->declarer();

        $collection = DocumentMeta::forOperation($declarer, OperationType::FetchCollection, 'notes');
        self::assertTrue($collection->collection);
        self::assertSame(['unread'], \array_map(static fn($field): string => $field->name(), $collection->fields));

        self::assertFalse(DocumentMeta::forOperation($declarer, OperationType::FetchOne, 'notes')->collection);
        self::assertSame(['etag'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forRelated($declarer, false, 'notes')->fields));
        self::assertSame(['unread'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forRelated($declarer, true, 'notes')->fields));
        self::assertTrue(DocumentMeta::forRelated($declarer, true)->collection);
    }

    #[Test]
    public function aRelationshipWriteNamesItsRelationToAnOverrideThatDeclaresIt(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'notes';

            /**
             * @var list<array{OperationType, ?string}>
             */
            public array $calls = [];

            public function fields(): array
            {
                return [Id::make()];
            }

            public function documentMeta(OperationType $operation, ?string $relation = null): iterable
            {
                $this->calls[] = [$operation, $relation];

                return $operation === OperationType::UpdateRelationship && $relation === 'tags' ? [Integer::make('retagged')] : [];
            }
        };

        $tags = DocumentMeta::forOperation($resource, OperationType::UpdateRelationship, 'notes', 'tags');
        self::assertSame(['retagged'], \array_map(static fn($field): string => $field->name(), $tags->fields));
        self::assertSame('the "notes" UpdateRelationship document of "tags"', $tags->owner);
        self::assertTrue(DocumentMeta::forOperation($resource, OperationType::UpdateRelationship, 'notes', 'author')->isEmpty());

        // Any other case is called with null, whatever relation the caller names.
        DocumentMeta::forOperation($resource, OperationType::FetchOne, 'notes', 'tags');
        self::assertSame([OperationType::FetchOne, null], $resource->calls[2]);
    }

    #[Test]
    public function anOverrideIgnoringTheRelationDeclaresTheSameMembersOnEveryRelationship(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'notes';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function documentMeta(OperationType $operation, ?string $relation = null): iterable
            {
                return $operation === OperationType::AddRelationship ? [Integer::make('added')] : [];
            }
        };

        foreach (['tags', 'author'] as $relation) {
            self::assertSame(['added'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forOperation($resource, OperationType::AddRelationship, 'notes', $relation)->fields));
        }
    }

    #[Test]
    public function aDeletesMembersApplyOnlyToItsMetaOnly200(): void
    {
        $declarer = new class implements DeclaresDocumentMetaInterface {
            public function documentMeta(OperationType $operation, ?string $relation = null): iterable
            {
                return $operation === OperationType::Delete ? [Str::make('archivedAs')->extractUsing(static fn(\stdClass $note): string => 'archive-' . $note->id)] : [];
            }
        };

        self::assertTrue(DocumentMeta::forDelete($declarer, [new NoContent()], 'notes')->isEmpty());

        $meta = DocumentMeta::forDelete($declarer, [new MetaResult()], 'notes');
        self::assertSame(['archivedAs'], \array_map(static fn($field): string => $field->name(), $meta->fields));

        $removed = new \stdClass();
        $removed->id = '7';
        self::assertSame(['archivedAs' => 'archive-7'], $meta->applyTo(MetaResponse::fromMeta([]), self::request(), $removed)->meta());

        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('The top-level meta of the "notes" Delete document is declared both in documentMeta() and on its MetaResult');
        DocumentMeta::forDelete($declarer, [new MetaResult([Integer::make('count')])], 'notes');
    }

    #[Test]
    public function anActionsMembersArePickedByStatus(): void
    {
        $responds = [
            new ActionResource('notes', meta: [Integer::make('marked')]),
            new ActionCreated('notes', meta: [Str::make('batch')]),
        ];

        self::assertSame(['marked'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forActionStatus($responds, 200)->fields));
        self::assertSame(['batch'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forActionStatus($responds, 201)->fields));
        self::assertTrue(DocumentMeta::forActionStatus([new NoContent()], 200)->isEmpty());
    }

    #[Test]
    public function aHandlerDeclaresItsActionsMembersPerStatus(): void
    {
        $handler = new class implements DeclaresActionDocumentMetaInterface {
            public function documentMeta(int $status): iterable
            {
                return match ($status) {
                    200 => [Integer::make('marked')->required()->example(12)],
                    201 => [Str::make('batch')],
                    default => [],
                };
            }
        };

        $responds = ActionResponses::withDocumentMeta([new ActionResource('notes'), new ActionCreated('notes'), new NoContent()], $handler);

        self::assertInstanceOf(ActionResource::class, $responds[0]);
        self::assertSame(['marked'], \array_map(static fn($field): string => $field->name(), $responds[0]->meta));
        self::assertInstanceOf(ActionCreated::class, $responds[1]);
        self::assertSame(['batch'], \array_map(static fn($field): string => $field->name(), $responds[1]->meta));
        self::assertInstanceOf(NoContent::class, $responds[2]);
        self::assertSame([], ActionResponses::withDocumentMeta([new MetaResult()], new class implements DeclaresActionDocumentMetaInterface {
            public function documentMeta(int $status): iterable
            {
                return [];
            }
        })[0]->meta ?? null);

        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('declares its meta on the response and in documentMeta()');
        ActionResponses::withDocumentMeta([new ActionResource('notes', meta: [Integer::make('other')])], $handler);
    }

    #[Test]
    public function anExplicitStatusIsReadable(): void
    {
        self::assertNull(MetaResponse::fromMeta([])->explicitStatus());
        self::assertSame(201, MetaResponse::fromMeta([])->withStatus(201)->explicitStatus());
    }

    #[Test]
    public function extractUsingFillsMembersAndASetValueWins(): void
    {
        $meta = DocumentMeta::forOperation($this->declarer(), OperationType::FetchCollection, 'notes');
        $request = self::request();

        self::assertSame(['unread' => 2], $meta->extract([['read' => false], ['read' => true], ['read' => false]], $request));
        self::assertSame(['unread' => 2, 'page' => ['number' => 1]], $meta->fill(['page' => ['number' => 1]], [['read' => false], ['read' => false]], $request));
        self::assertSame(['unread' => 9], $meta->fill(['unread' => 9], [], $request));

        $set = new DocumentMeta([Integer::make('marked')->required()]);
        self::assertSame(['other' => 1], $set->fill(['other' => 1], [], $request), 'a member with no hook is left to the handler');
    }

    #[Test]
    public function aResponseIsFilledFromItsPrimaryData(): void
    {
        $serializer = new class extends AbstractResource {
            public static string $type = 'notes';

            public function fields(): array
            {
                return [Id::make()];
            }
        };
        $request = self::request();
        $collection = DocumentMeta::forOperation($this->declarer(), OperationType::FetchCollection, 'notes');

        $filled = $collection->applyTo(DataResponse::fromCollection([['read' => false], ['read' => true]], $serializer)->withMeta(['total' => 2]), $request);
        self::assertSame(['unread' => 1, 'total' => 2], $filled->meta());

        $related = $collection->applyTo(RelatedResponse::fromCollection(new \ArrayIterator([['read' => false]]), $serializer), $request);
        self::assertSame(['unread' => 1], $related->meta());

        $single = new DocumentMeta([Str::make('seen')->extractUsing(static fn(mixed $note): string => \is_array($note) ? 'one' : 'none')]);
        self::assertSame(['seen' => 'one'], $single->applyTo(DataResponse::fromResource(['read' => true], $serializer), $request)->meta());
        self::assertSame(['seen' => 'none'], $single->applyTo(RelatedResponse::fromResource(null, $serializer), $request)->meta());

        $untouched = DataResponse::fromResource([], $serializer);
        self::assertSame($untouched, (new DocumentMeta([Str::make('etag')]))->applyTo($untouched, $request));
    }

    #[Test]
    public function theHookSeesTheRequest(): void
    {
        $seen = null;
        $meta = new DocumentMeta([Str::make('path')->extractUsing(static function (mixed $data, JsonApiRequestInterface $request) use (&$seen): string {
            $seen = $request;

            return $request->getUri()->getPath();
        })]);
        $request = self::request('/notes/1');

        self::assertSame(['path' => '/notes/1'], $meta->extract(null, $request));
        self::assertSame($request, $seen);
    }

    #[Test]
    public function aConformingMetaHasNoViolations(): void
    {
        $meta = new DocumentMeta([
            Integer::make('unread')->required(),
            Str::make('etag'),
            Obj::make('window')->fields(Integer::make('from')),
        ], collection: true);

        self::assertSame([], $meta->violations(['unread' => 3, 'page' => ['number' => 1], 'total' => 10]));
        self::assertSame([], $meta->violations(['unread' => 3, 'etag' => 'x', 'window' => ['from' => 1]]));
        self::assertSame([], $meta->violations(['unread' => 3, 'window' => []]));
        $meta->assertSatisfiedBy(['unread' => 0]);
    }

    #[Test]
    public function aMismatchIsReportedLoudly(): void
    {
        $meta = new DocumentMeta([Integer::make('unread')->required(), Boolean::make('stale')], owner: 'the "notes" FetchOne document');

        self::assertSame(['the required member "unread" is missing'], $meta->violations([]));
        self::assertSame(['the member "unread" is string, not integer'], $meta->violations(['unread' => '3']));
        self::assertSame(['the member "page" is not declared'], $meta->violations(['unread' => 1, 'page' => []]));
        self::assertSame(['the member "stale" is integer, not boolean or null'], $meta->violations(['unread' => 1, 'stale' => 1]));

        $this->expectException(DocumentMetaMismatch::class);
        $this->expectExceptionMessage('The top-level meta of the "notes" FetchOne document does not match its declaration: the required member "unread" is missing; the member "extra" is not declared.');
        $meta->assertSatisfiedBy(['extra' => true]);
    }

    #[Test]
    public function aDeclarationWithNoMembersChecksNothing(): void
    {
        self::assertSame([], (new DocumentMeta())->violations(['anything' => 1]));
    }

    #[Test]
    public function aCollectionMemberClashingWithCoreIsRefused(): void
    {
        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('The top-level meta of the "notes" FetchCollection document declares the member "page", which core writes into every collection document. Rename the member.');

        new DocumentMeta([Integer::make('page')], collection: true, owner: 'the "notes" FetchCollection document');
    }

    #[Test]
    public function aSingleDocumentMayUseThoseNames(): void
    {
        self::assertCount(2, (new DocumentMeta([Integer::make('page'), Integer::make('total')]))->fields);
    }

    #[Test]
    public function aMemberDeclaredTwiceIsRefused(): void
    {
        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('declares the member "marked" twice');

        new ActionResource('notes', meta: [Integer::make('marked'), Str::make('marked')]);
    }

    #[Test]
    public function actionResponsesCarryTheirMembers(): void
    {
        self::assertSame(['marked'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forResponse(new ActionResource('notes', meta: [Integer::make('marked')->example(1)]))->fields));
        self::assertSame(['batch'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forResponse(new ActionCreated('notes', meta: [Str::make('batch')]))->fields));
        self::assertSame(['count'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forResponse(new MetaResult([Integer::make('count')]))->fields));
        self::assertTrue(DocumentMeta::forResponse(new NoContent())->isEmpty());
        self::assertTrue(DocumentMeta::forResponse(new MetaResult())->isEmpty());
        self::assertSame(['archived'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forDelete(new \stdClass(), [new NoContent(), new MetaResult([Integer::make('archived')])])->fields));
    }

    #[Test]
    public function requiredMembersMakeMetaRequired(): void
    {
        self::assertTrue((new DocumentMeta([Integer::make('a')->required()]))->hasRequired());
        self::assertFalse((new DocumentMeta([Integer::make('a')]))->hasRequired());
    }

    private function declarer(): DeclaresDocumentMetaInterface
    {
        return new class implements DeclaresDocumentMetaInterface {
            public function documentMeta(OperationType $operation, ?string $relation = null): iterable
            {
                return match ($operation) {
                    OperationType::FetchCollection => [
                        Integer::make('unread')->required()->extractUsing(
                            static fn(array $items): int => \count(\array_filter($items, static fn(mixed $item): bool => \is_array($item) && $item['read'] === false)),
                        ),
                    ],
                    OperationType::FetchOne => [Str::make('etag')],
                    default => [],
                };
            }
        };
    }

    private static function request(string $path = '/notes'): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', 'https://example.com' . $path));
    }
}
