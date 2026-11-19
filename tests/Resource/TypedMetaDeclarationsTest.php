<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Hydrator\DeclaresRequestDocumentMetaInterface;
use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\DeclaresActionDocumentMetaInterface;
use haddowg\JsonApi\Resource\DeclaresActionRequestMetaInterface;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\MetaMisdeclared;
use haddowg\JsonApi\Resource\RelationshipMetaContext;
use haddowg\JsonApi\Resource\RequestMeta;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Resource\WrittenMeta;
use haddowg\JsonApi\Serializer\DeclaresRelationshipDocumentMetaInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestMetaContext::class)]
#[CoversClass(ActionRequestMetaContext::class)]
#[CoversClass(MetaContext::class)]
#[CoversClass(RelationshipMetaContext::class)]
#[CoversClass(RequestMeta::class)]
#[CoversClass(ResourceMeta::class)]
#[CoversClass(WrittenMeta::class)]
#[CoversClass(AdditionalProperties::class)]
#[CoversClass(AbstractResource::class)]
final class TypedMetaDeclarationsTest extends TestCase
{
    #[Test]
    public function aRequestContextNamesTheWriteItDescribes(): void
    {
        $create = RequestMetaContext::forOperation(OperationType::Create);
        self::assertTrue($create->is(OperationType::Create));
        self::assertFalse($create->isRelationship());
        self::assertFalse($create->isAtomic());
        self::assertSame('Create', $create->describe());

        $add = RequestMetaContext::forRelationship('items', Mode::Add, atomic: true);
        self::assertFalse($add->is(OperationType::Update));
        self::assertTrue($add->isRelationship());
        self::assertTrue($add->isRelationship('items'));
        self::assertTrue($add->isRelationship('items', Mode::Add));
        self::assertFalse($add->isRelationship('items', Mode::Remove));
        self::assertFalse($add->isRelationship('tags'));
        self::assertTrue($add->isAtomic());
        self::assertSame('atomic "items" relationship add', $add->describe());
    }

    #[Test]
    public function aRequestContextIsOnlyForAWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RequestMetaContext::forOperation(OperationType::FetchOne);
    }

    #[Test]
    public function anActionContextComparesItsMethodInAnyCase(): void
    {
        $context = new ActionRequestMetaContext('post');

        self::assertSame('POST', $context->method);
        self::assertTrue($context->is('POST'));
        self::assertTrue($context->is('post'));
        self::assertFalse($context->is('GET'));
    }

    #[Test]
    public function aMetaContextIsARreadOrAWrite(): void
    {
        $read = MetaContext::read();
        self::assertTrue($read->isRead());
        self::assertFalse($read->isWrite());
        self::assertFalse($read->creating());

        $create = MetaContext::write(OperationType::Create, atomic: true);
        self::assertTrue($create->isWrite());
        self::assertTrue($create->is(OperationType::Create));
        self::assertTrue($create->creating());
        self::assertTrue($create->isAtomic());

        self::assertFalse(MetaContext::write(OperationType::Update)->creating());

        $this->expectException(\InvalidArgumentException::class);
        MetaContext::write(OperationType::Delete);
    }

    #[Test]
    public function aRelationshipContextNamesTheRelationAndMutation(): void
    {
        $context = new RelationshipMetaContext('items', Mode::Remove);

        self::assertTrue($context->isRelationship('items'));
        self::assertTrue($context->isRelationship('items', Mode::Remove));
        self::assertFalse($context->isRelationship('tags'));
        self::assertTrue($context->is(Mode::Remove));
        self::assertFalse($context->isAtomic());
        self::assertSame('"items" relationship remove', $context->describe());
    }

    #[Test]
    public function aResourceDeclaresNoMetaByDefault(): void
    {
        $resource = self::resource();

        self::assertTrue(RequestMeta::forOperation($resource, RequestMetaContext::forOperation(OperationType::Create), 'bookings')->isEmpty());
        self::assertTrue(ResourceMeta::forType($resource, MetaContext::read(), 'bookings')->isEmpty());
        self::assertTrue(DocumentMeta::forRelationship($resource, new RelationshipMetaContext('items', Mode::Add), 'bookings')->isEmpty());
        self::assertTrue(RequestMeta::forOperation(new \stdClass(), RequestMetaContext::forOperation(OperationType::Create))->isEmpty());
        self::assertTrue(RequestMeta::forAction(new \stdClass(), new ActionRequestMetaContext('POST'))->isEmpty());
    }

    #[Test]
    public function aResourceDeclaresItsRequestMetaPerWrite(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function requestDocumentMeta(RequestMetaContext $context): iterable
            {
                return match (true) {
                    $context->is(OperationType::Create) => [Str::make('idempotencyKey')->required()],
                    $context->isRelationship('items') => [Str::make('reason')],
                    default => [],
                };
            }
        };

        $create = RequestMeta::forOperation($resource, RequestMetaContext::forOperation(OperationType::Create), 'bookings');
        self::assertSame('idempotencyKey', $create->fields[0]->name());
        self::assertSame('the "bookings" Create request', $create->owner);
        self::assertSame('reason', RequestMeta::forOperation($resource, RequestMetaContext::forRelationship('items', Mode::Replace), 'bookings')->fields[0]->name());
        self::assertTrue(RequestMeta::forOperation($resource, RequestMetaContext::forOperation(OperationType::Delete), 'bookings')->isEmpty());
    }

    #[Test]
    public function aComposedHydratorDeclaresThroughTheInterface(): void
    {
        $hydrator = new class implements DeclaresRequestDocumentMetaInterface {
            public function requestDocumentMeta(RequestMetaContext $context): iterable
            {
                return $context->isAtomic() ? [Str::make('batch')] : [Str::make('single')];
            }
        };

        self::assertSame('batch', RequestMeta::forOperation($hydrator, RequestMetaContext::forOperation(OperationType::Update, true))->fields[0]->name());
        self::assertSame('single', RequestMeta::forOperation($hydrator, RequestMetaContext::forOperation(OperationType::Update))->fields[0]->name());
    }

    #[Test]
    public function anActionHandlerDeclaresItsRequestMetaPerMethod(): void
    {
        $handler = new class implements DeclaresActionRequestMetaInterface {
            public function requestDocumentMeta(ActionRequestMetaContext $context): iterable
            {
                return $context->is('POST') ? [Str::make('silent')] : [];
            }
        };

        $post = RequestMeta::forAction($handler, new ActionRequestMetaContext('POST'), '"confirm" action');
        self::assertSame('silent', $post->fields[0]->name());
        self::assertSame('the POST "confirm" action request', $post->owner);
        self::assertTrue(RequestMeta::forAction($handler, new ActionRequestMetaContext('GET'))->isEmpty());
    }

    #[Test]
    public function aMisdeclaredRequestMetaIsRefused(): void
    {
        $hydrator = new class implements DeclaresRequestDocumentMetaInterface {
            public function requestDocumentMeta(RequestMetaContext $context): iterable
            {
                return [Str::make('reason'), Str::make('reason')];
            }
        };

        $this->expectException(MetaMisdeclared::class);
        RequestMeta::forOperation($hydrator, RequestMetaContext::forOperation(OperationType::Create));
    }

    #[Test]
    public function renderedResourceMetaMergesHookedMembersUnderGetMeta(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function resourceMeta(MetaContext $context): iterable
            {
                return [
                    Integer::make('nights')->readOnly()->extractUsing(static fn(array $booking): int => $booking['nights']),
                    Str::make('note')->readOnly(),
                    AdditionalProperties::make()->extractUsing(static fn(array $booking): array => $booking['labels']),
                ];
            }

            public function getMeta(mixed $object, JsonApiRequestInterface $request): array
            {
                return ['note' => 'from getMeta', 'nights' => 9];
            }
        };

        $meta = ResourceMeta::render($resource, ['id' => '1', 'nights' => 3, 'labels' => ['vip' => true]], self::request());

        self::assertSame(['nights' => 9, 'vip' => true, 'note' => 'from getMeta'], $meta);
        self::assertSame(ResourceMeta::readShapeOf($resource, 'bookings'), ResourceMeta::readShapeOf($resource, 'bookings'));
    }

    #[Test]
    public function renderedResourceMetaIsGetMetaAloneWithoutADeclaration(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function getMeta(mixed $object, JsonApiRequestInterface $request): array
            {
                return ['free' => 'form'];
            }
        };

        self::assertSame(['free' => 'form'], ResourceMeta::render($resource, ['id' => '1'], self::request()));
    }

    #[Test]
    public function aWriteContextReadsTheWriteDeclaration(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function resourceMeta(MetaContext $context): iterable
            {
                return $context->isRead() ? [Str::make('read')] : [Str::make($context->creating() ? 'create' : 'update')];
            }
        };

        self::assertSame('read', ResourceMeta::forType($resource, MetaContext::read())->fields[0]->name());
        self::assertSame('create', ResourceMeta::forType($resource, MetaContext::write(OperationType::Create))->fields[0]->name());
        self::assertSame('update', ResourceMeta::forType($resource, MetaContext::write(OperationType::Update))->fields[0]->name());
        self::assertSame('the "bookings" resource object create request', ResourceMeta::forType($resource, MetaContext::write(OperationType::Create), 'bookings')->owner);
    }

    #[Test]
    public function writtenMetaRidesOnTheRequest(): void
    {
        $request = self::request();
        self::assertSame([], WrittenMeta::of($request)->document);

        $written = new WrittenMeta(['reason' => 'late'], ['source' => 'web']);
        $carrying = $written->on($request);

        self::assertInstanceOf(JsonApiRequest::class, $carrying);
        self::assertSame($written, WrittenMeta::of($carrying));
        self::assertSame('late', WrittenMeta::of($carrying)->document('reason'));
        self::assertSame('fallback', WrittenMeta::of($carrying)->document('missing', 'fallback'));
        self::assertSame('web', WrittenMeta::of($carrying)->resource('source'));
        self::assertNull(WrittenMeta::of($carrying)->resource('missing'));
    }

    #[Test]
    public function theEntryBuilderTakesAValueFieldAndHooks(): void
    {
        $entry = AdditionalProperties::make()
            ->of(Integer::make('*'))
            ->extractUsing(static fn(array $row): array => $row)
            ->fillUsing(static function (\ArrayObject $model, array $members): void {
                $model['members'] = $members;
            })
            ->build();

        self::assertSame('*', $entry->name());
        self::assertSame('integer', (new \haddowg\JsonApi\OpenApi\SchemaProjector())->projectField($entry->of() ?? Str::make('x')->build())->toArray()['type'] ?? null);
        self::assertSame(['a' => 1], $entry->extract(['a' => 1], self::request()));
        self::assertSame(['a' => 1], $entry->cast(['a' => 1]));
        self::assertTrue($entry->fills());

        $model = new \ArrayObject();
        $entry->fill($model, ['x' => 1], []);
        self::assertSame(['members' => ['x' => 1]], $model->getArrayCopy());

        $plain = AdditionalProperties::make()->build();
        self::assertNull($plain->of());
        self::assertSame([], $plain->extract([], self::request()));
        self::assertFalse($plain->fills());
        self::assertSame(['m'], $plain->fill(['m'], ['x' => 1], []));
        self::assertSame(['x' => 'y'], $plain->cast(['x' => 'y']));
    }

    #[Test]
    public function anExtractHookReturningNoObjectRendersNoExtras(): void
    {
        self::assertSame([], AdditionalProperties::make()->extractUsing(static fn(): string => 'oops')->build()->extract([], self::request()));
    }

    #[Test]
    public function theEntryTakesNoRelationAsItsValue(): void
    {
        $this->expectException(\LogicException::class);

        AdditionalProperties::make()->of(BelongsTo::make('owner', 'people'))->build();
    }

    #[Test]
    public function anEntryBuiltUnderAnotherNameIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        (new \haddowg\JsonApi\Resource\Field\AdditionalPropertiesBuilder('extras'))->build();
    }

    #[Test]
    public function documentMetaKeepsItsEntry(): void
    {
        $meta = new DocumentMeta([Integer::make('unread'), AdditionalProperties::make()->of(Str::make('*'))->extractUsing(static fn(array $page): array => ['label' => 'x'])]);

        self::assertSame(['unread'], \array_map(static fn($field): string => $field->name(), $meta->fields));
        self::assertNotNull($meta->additional);
        self::assertCount(2, $meta->declaration());
        self::assertTrue($meta->shape()->isOpen());
        self::assertFalse($meta->isEmpty());
        self::assertFalse((new DocumentMeta([AdditionalProperties::make()]))->isEmpty());
        self::assertSame(['label' => 'x'], $meta->extract([], self::request()));
        self::assertSame([], $meta->violations(['unread' => 1, 'extra' => 'y']));
        self::assertSame(['the member "extra" is integer, not string or null'], $meta->violations(['extra' => 1]));
    }

    #[Test]
    public function documentMetaRefusesTwoEntries(): void
    {
        $this->expectException(DocumentMetaMisdeclared::class);

        new DocumentMeta([AdditionalProperties::make(), AdditionalProperties::make()]);
    }

    #[Test]
    public function aRelationshipWriteDeclaresItsMetaOnlyDocument(): void
    {
        $declarer = new class implements DeclaresRelationshipDocumentMetaInterface {
            public function relationshipDocumentMeta(RelationshipMetaContext $context): iterable
            {
                return $context->isRelationship('items', Mode::Add) ? [Integer::make('moved')->required()] : [];
            }
        };

        $add = DocumentMeta::forRelationship($declarer, new RelationshipMetaContext('items', Mode::Add), 'bookings');
        self::assertSame('moved', $add->fields[0]->name());
        self::assertFalse($add->collection);
        self::assertSame('the "bookings" "items" relationship add document', $add->owner);
        self::assertTrue(DocumentMeta::forRelationship($declarer, new RelationshipMetaContext('items', Mode::Remove))->isEmpty());
    }

    #[Test]
    public function aStatus202ReadsTheAcceptedMeta(): void
    {
        $accepted = new Accepted('jobs', [Integer::make('position')]);
        self::assertSame(['position'], \array_map(static fn($field): string => $field->name(), $accepted->meta));
        self::assertSame(['eta'], \array_map(static fn($field): string => $field->name(), $accepted->withMeta([Str::make('eta')])->meta));

        $meta = DocumentMeta::forActionStatus([new ActionResource('bookings'), $accepted], 202);
        self::assertSame(['position'], \array_map(static fn($field): string => $field->name(), $meta->fields));
        self::assertSame(['position'], \array_map(static fn($field): string => $field->name(), DocumentMeta::forResponse($accepted)->fields));
    }

    #[Test]
    public function aHandlerMapsItsDocumentMetaOntoAnAccepted(): void
    {
        $handler = new class implements DeclaresActionDocumentMetaInterface {
            public function documentMeta(int $status): iterable
            {
                return $status === 202 ? [Integer::make('position')] : [];
            }
        };

        $responds = ActionResponses::withDocumentMeta([new Accepted('jobs')], $handler);
        self::assertInstanceOf(Accepted::class, $responds[0]);
        self::assertSame(['position'], \array_map(static fn($field): string => $field->name(), $responds[0]->meta));
    }

    private static function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'bookings';

            public function fields(): array
            {
                return [Id::make(), HasMany::make('items', 'items')];
            }
        };
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('POST', '/bookings'));
    }
}
