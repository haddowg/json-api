<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Exception\FieldsetMemberUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\NestedFieldsets;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Profile\NestedFieldsetsProfile;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\RecordingOperationHandler;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(NestedFieldsets::class)]
#[CoversClass(NestedFieldset::class)]
#[CoversClass(\haddowg\JsonApi\Resource\Field\NestedFieldsetKeys::class)]
#[CoversClass(NestedFieldsetsProfile::class)]
#[Group('spec:sparse-fieldsets')]
#[Group('spec:extensions-and-profiles')]
final class NestedFieldsetsTest extends TestCase
{
    protected function setUp(): void
    {
        FieldsetProductResource::$extracted = [];
        FieldsetPersonResource::$extracted = [];
        OrderFieldsetResource::$extracted = [];
        FieldsetProductResource::$serialized = [];
        FieldsetPersonResource::$serialized = [];
        OrderFieldsetResource::$serialized = [];
    }

    #[Test]
    public function aKeyMemberRendersOnlyThatKeyOfTheAttribute(): void
    {
        $body = $this->body($this->render('name,values.cap,values.countries'));

        self::assertSame(['name' => 'Widget', 'values' => ['cap' => '10', 'countries' => 'GB,FR']], $this->attributes($body));
    }

    #[Test]
    public function listingTheWholeAttributeBesideAKeyRendersAllOfIt(): void
    {
        $body = $this->body($this->render('values,values.cap'));

        self::assertSame(['values' => ['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU']], $this->attributes($body));
    }

    #[Test]
    public function noFieldsetLeavesTheResponseUnchanged(): void
    {
        $response = $this->render(null);
        $body = $this->body($response);

        self::assertSame(['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU'], $this->attributes($body)['values']);
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['version' => '1.1'], $body['jsonapi']);
    }

    #[Test]
    public function aFieldsetNarrowingNothingAdvertisesNoProfile(): void
    {
        $response = $this->render('name,values');

        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['version' => '1.1'], $this->body($response)['jsonapi']);
    }

    #[Test]
    public function aNarrowedResponseAppliesAndAdvertisesTheProfile(): void
    {
        $response = $this->render('values.cap');

        self::assertSame('application/vnd.api+json; profile="' . NestedFieldsetsProfile::URI . '"', $response->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $response->getHeaderLine('Vary'));
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $this->body($response)['jsonapi']);
    }

    #[Test]
    public function aServerNotRecognizingTheProfileNarrowsWithoutAdvertisingIt(): void
    {
        $response = $this->render('values.cap', $this->server(withProfile: false));

        self::assertSame(['values' => ['cap' => '10']], $this->attributes($this->body($response)));
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anExtractHookReceivesTheSelectionAndIsTrimmedWhenItIgnoresIt(): void
    {
        $body = $this->body($this->render('computed.cap'));

        self::assertSame(['computed' => ['cap' => 10]], $this->attributes($body));
        self::assertSame([['cap']], FieldsetProductResource::$extracted);
    }

    #[Test]
    public function anExtractHookReceivesNullWhenTheAttributeIsNotNarrowed(): void
    {
        $this->render('computed');

        self::assertSame([null], FieldsetProductResource::$extracted);
    }

    #[Test]
    public function aMapRendersOnlyTheSelectedChildren(): void
    {
        $body = $this->body($this->render('totals.views'));

        self::assertSame(['totals' => ['views' => 4]], $this->attributes($body));
    }

    #[Test]
    public function aRepeatingGroupIsSelectedAsOneKey(): void
    {
        $body = $this->body($this->render('groups.rows'));

        self::assertSame(['groups' => ['rows' => [['a' => 1], ['a' => 2]]]], $this->attributes($body));
    }

    #[Test]
    public function aSelectionAppliesToAnIncludedResource(): void
    {
        $body = $this->body($this->render('name,vendor', include: 'vendor', vendorFields: 'stats.rating'));

        self::assertIsArray($body['included']);
        self::assertIsArray($body['included'][0]);
        self::assertSame(['stats' => ['rating' => 5]], $body['included'][0]['attributes']);
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $body['jsonapi']);
    }

    #[Test]
    public function aSelectionAppliesToARelatedResponse(): void
    {
        $server = $this->server();
        $request = $this->request(null, vendorFields: 'stats.rating');
        $response = RelatedResponse::fromResource(['id' => '9', 'stats' => ['rating' => 5, 'reviews' => 12]], $server->serializerFor('vendors'))
            ->toPsrResponse($server, $request);

        $body = $this->body($response);
        self::assertSame(['stats' => ['rating' => 5]], $this->attributes($body));
        self::assertStringContainsString(NestedFieldsetsProfile::URI, $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anAtomicResultsResponseAdvertisesTheProfileWhenNarrowed(): void
    {
        $server = $this->server();
        $response = AtomicResultsResponse::fromResults([AtomicResult::empty()])
            ->toPsrResponse($server, $this->request('values.cap'));

        self::assertStringContainsString('profile="' . NestedFieldsetsProfile::URI . '"', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aMetaResponseIsNeverNarrowed(): void
    {
        $response = MetaResponse::fromMeta(['ok' => true])->toPsrResponse($this->server(), $this->request('values.cap'));

        self::assertStringNotContainsString('profile', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function aRejectedKeyIsTheUnrecognizedFieldsetMember400(): void
    {
        $this->assertUnrecognized('values.Bad-Key', ['values.Bad-Key']);
    }

    #[Test]
    public function aKeyThePredicateRejectsIsTheUnrecognizedFieldsetMember400(): void
    {
        $this->assertUnrecognized('totals.internal', ['totals.internal']);
    }

    #[Test]
    public function aDottedMemberOnAnAttributeWithoutNestedFieldsetKeysIsThe400(): void
    {
        $this->assertUnrecognized('name.first', ['name.first']);
    }

    #[Test]
    public function aDottedMemberOnARelationshipIsThe400(): void
    {
        $this->assertUnrecognized('vendor.stats', ['vendor.stats']);
    }

    #[Test]
    public function aDeeperPathOnARepeatingGroupSelectsNothingBelowIt(): void
    {
        $this->assertUnrecognized('values.rows.a', ['values.rows.a']);

        // `rows.a` is a path into `rows`, a list whose element declares no nested fieldset.
        $this->assertUnrecognized('groups.rows.a', ['groups.rows.a']);
        $body = $this->body($this->render('name,groups.rows.a'));
        self::assertSame(['name' => 'Widget'], $this->attributes($body));
    }

    #[Test]
    public function anEmptyKeyIsThe400(): void
    {
        $this->assertUnrecognized('values.', ['values.']);
    }

    #[Test]
    public function acceptedKeysPassStrictValidation(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = $this->server()->withHandler($handler);

        $operation = new FieldsetOperation($this->request('name,values.cap,totals.views'));
        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function withoutStrictValidationARejectedKeySelectsNothing(): void
    {
        $body = $this->body($this->render('name,values.Bad-Key,name.first'));

        self::assertSame(['name' => 'Widget'], $this->attributes($body));
    }

    #[Test]
    public function aSparseByDefaultAttributeRendersWhenAKeyOfItIsSelected(): void
    {
        self::assertSame(['hiddenUntilAsked' => ['x' => 1]], $this->attributes($this->body($this->render('hiddenUntilAsked.x'))));
        self::assertArrayNotHasKey('hiddenUntilAsked', $this->attributes($this->body($this->render(null))));
    }

    #[Test]
    public function aNonSparseAttributeRendersWholeUnlessKeysOfItAreSelected(): void
    {
        $server = $this->server();
        $shelf = ['id' => '3', 'label' => 'Top', 'pinned' => ['a' => 1, 'b' => 2]];
        $render = fn(string $fields): array => $this->attributes($this->body(
            DataResponse::fromResource($shelf, $server->serializerFor('shelves'))
                ->toPsrResponse($server, new JsonApiRequest((new ServerRequest('GET', '/shelves/3'))->withQueryParams(['fields' => ['shelves' => $fields]]))),
        ));

        self::assertSame(['label' => 'Top', 'pinned' => ['a' => 1, 'b' => 2]], $render('label'));
        self::assertSame(['label' => 'Top', 'pinned' => ['b' => 2]], $render('label,pinned.b'));
    }

    #[Test]
    public function aClosedObjectSelectsItsDeclaredChildren(): void
    {
        self::assertSame(['address' => ['postcode' => 'AB1 2CD']], $this->personAttributes('address.postcode'));
    }

    #[Test]
    public function aNestedKeyMemberRendersOnlyThatPathAndNothingElseOfItsAncestors(): void
    {
        self::assertSame(['address' => ['geo' => ['lat' => 51.5]]], $this->personAttributes('address.geo.lat'));
    }

    #[Test]
    public function keysAtSeveralDepthsRenderSideBySide(): void
    {
        self::assertSame(
            ['name' => 'Ada', 'address' => ['postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1]]],
            $this->personAttributes('name,address.postcode,address.geo.lat,address.geo.lng'),
        );
    }

    #[Test]
    public function aKeyListedWholeBesideAPathIntoItRendersWholeAtEveryDepth(): void
    {
        self::assertSame(['address' => ['geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop']]], $this->personAttributes('address.geo,address.geo.lat'));
        self::assertSame(['address' => ['geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop']]], $this->personAttributes('address.geo.lat,address.geo'));
        self::assertSame(
            ['address' => ['street' => '1 High Street', 'postcode' => 'AB1 2CD', 'geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop'], 'Region' => null, 'plain' => null, 'history' => [['street' => '9 Low Road']]]],
            $this->personAttributes('address,address.geo.lat'),
        );
    }

    #[Test]
    public function anOpenObjectsMembersAreNarrowedThroughTheirMemberField(): void
    {
        self::assertSame(['scores' => ['maths' => ['max' => 9], 'art' => ['min' => 2]]], $this->personAttributes('scores.maths.max,scores.art.min'));
        self::assertSame(['scores' => ['maths' => ['min' => 4, 'max' => 9]]], $this->personAttributes('scores.maths'));
    }

    #[Test]
    public function aMapsChildIsNarrowedInTurn(): void
    {
        self::assertSame(['contact' => ['phone' => ['work' => '0200']]], $this->personAttributes('contact.phone.work'));
    }

    #[Test]
    public function anExtractHookReceivesTheNestedFieldsetInsideAKey(): void
    {
        self::assertSame(['office' => ['geo' => ['lng' => -0.2]]], $this->personAttributes('office.geo.lng'));
        self::assertSame([['paths' => ['geo.lng'], 'keys' => ['geo'], 'geo' => ['lng']]], FieldsetPersonResource::$extracted);
    }

    #[Test]
    public function aSerializeHooksValueIsTrimmedAtEveryDepth(): void
    {
        self::assertSame(['badge' => ['colour' => ['fg' => 'black']]], $this->personAttributes('badge.colour.fg'));
        self::assertSame([['colour.fg']], FieldsetPersonResource::$serialized);
    }

    #[Test]
    public function aSerializeHookReceivesNullWhenTheAttributeIsNotNarrowed(): void
    {
        $this->personAttributes('badge');
        $this->render('ratios');

        self::assertSame([null], FieldsetPersonResource::$serialized);
        self::assertSame([null], FieldsetProductResource::$serialized);
    }

    #[Test]
    public function aMapsSerializeHookReceivesTheSelectionAndIsTrimmed(): void
    {
        $body = $this->body($this->render('ratios.views'));

        self::assertSame(['ratios' => ['views' => 1]], $this->attributes($body));
        self::assertSame([['views']], FieldsetProductResource::$serialized);
    }

    #[Test]
    public function aNestedSelectionAppliesToAnIncludedResource(): void
    {
        $body = $this->body($this->render('name,vendor', include: 'vendor', vendorFields: 'office.geo.lat'));

        self::assertIsArray($body['included']);
        self::assertIsArray($body['included'][0]);
        self::assertSame(['office' => ['geo' => ['lat' => 48.8]]], $body['included'][0]['attributes']);
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $body['jsonapi']);
    }

    #[Test]
    public function aNestedKeyTheObjectRejectsIsThe400(): void
    {
        $this->assertUnrecognized('address.geo.alt', ['address.geo.alt'], 'people');
        $this->assertUnrecognized('address.Region.code', ['address.Region.code'], 'people');
    }

    #[Test]
    public function aPathBelowAKeyWhoseObjectDeclaresNoNestedFieldsetKeysIsThe400(): void
    {
        $this->assertUnrecognized('address.postcode.x', ['address.postcode.x'], 'people');
        $this->assertUnrecognized('address.plain.code', ['address.plain.code'], 'people');
        $this->assertUnrecognized('address.history.street', ['address.history.street'], 'people');
        $this->assertUnrecognized('address.missing.x', ['address.missing.x'], 'people');
    }

    #[Test]
    public function anEmptyNestedKeyIsThe400(): void
    {
        $this->assertUnrecognized('address.geo.', ['address.geo.'], 'people');
        $this->assertUnrecognized('address..lat', ['address..lat'], 'people');
    }

    #[Test]
    public function acceptedNestedPathsPassStrictValidation(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = $this->server()->withHandler($handler);

        $operation = new FieldsetOperation($this->personRequest('address.geo.lat,scores.maths.max,contact.phone.work'), 'people');
        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function withoutStrictValidationARejectedNestedPathSelectsNothing(): void
    {
        self::assertSame(['name' => 'Ada'], $this->personAttributes('name,address.geo.alt'));
        self::assertSame(['address' => ['postcode' => 'AB1 2CD']], $this->personAttributes('address.postcode,address.history.street'));
    }

    #[Test]
    public function narrowsReadsNestedPathsAgainstEachLevelsDeclaration(): void
    {
        $server = $this->server();

        self::assertTrue(NestedFieldsets::narrows($server, $this->personRequest('address.geo.lat')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->personRequest('address.geo.alt')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->personRequest('address,address.geo.lat')));
    }

    #[Test]
    public function theSelectionHoldsANestedSelectionPerNarrowedKey(): void
    {
        $address = FieldsetPersonResource::address();
        $request = $this->personRequest(null);

        $selection = NestedFieldsets::fieldset($address, ['postcode', 'geo.lat', 'geo.lng', 'geo.alt'], $request);

        self::assertNotNull($selection);
        self::assertSame(['postcode', 'geo'], $selection->keys);
        self::assertNull($selection->nested('postcode'));
        self::assertSame(['lat', 'lng'], $selection->nested('geo')?->keys);
        self::assertSame(['postcode', 'geo.lat', 'geo.lng'], $selection->paths());

        $whole = NestedFieldsets::fieldset($address, ['geo.lat', 'geo'], $request);
        self::assertNotNull($whole);
        self::assertSame(['geo'], $whole->keys);
        self::assertNull($whole->nested('geo'));

        self::assertNull(NestedFieldsets::fieldset($address, ['geo.alt', 'postcode.x'], $request));
    }

    #[Test]
    public function aWildcardSelectsTheKeyFromEveryItemKeepingTheListsLengthAndOrder(): void
    {
        $response = $this->renderOrder('lines.*.sku');

        self::assertSame(['lines' => [['sku' => 'A1'], ['sku' => 'B2'], ['sku' => null]]], $this->attributes($this->body($response)));
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $this->body($response)['jsonapi']);
    }

    #[Test]
    public function anItemLackingTheSelectedMemberRendersAsAnEmptyObject(): void
    {
        $response = $this->renderOrder('cells.*.a');

        self::assertStringContainsString('"attributes":{"cells":[{"a":"1"},{}]}', (string) $response->getBody());
    }

    #[Test]
    public function aWildcardKeepsAnEmptyListEmpty(): void
    {
        $response = $this->renderOrder('lines.*.sku', ['lines' => []]);

        self::assertStringContainsString('"attributes":{"lines":[]}', (string) $response->getBody());
    }

    #[Test]
    public function aWildcardWithNothingAfterItSelectsTheWholeList(): void
    {
        self::assertSame($this->orderAttributes('lines'), $this->orderAttributes('lines.*'));
        $lines = $this->orderAttributes('lines.*')['lines'] ?? null;
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
    }

    #[Test]
    public function aWildcardWithNothingAfterItDoesNotAdvertiseTheProfile(): void
    {
        foreach (['lines.*', 'reference,lines.*', 'lines.*,lines.*.sku', 'archived.*'] as $fields) {
            $response = $this->renderOrder($fields);

            self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'), $fields);
            self::assertSame(['version' => '1.1'], $this->body($response)['jsonapi'] ?? null, $fields);
        }
    }

    #[Test]
    public function aWildcardWithNothingAfterItListsASparseByDefaultListWhole(): void
    {
        self::assertSame($this->orderAttributes('archived'), $this->orderAttributes('archived.*'));
        self::assertSame(['archived' => [['sku' => 'Z9', 'qty' => 4]]], $this->orderAttributes('archived.*'));
        self::assertArrayNotHasKey('archived', $this->orderAttributes('reference'));
    }

    #[Test]
    public function aNestedListSelectedThroughAWildcardAloneIsSelectedWhole(): void
    {
        self::assertSame($this->orderAttributes('shipping.parcels'), $this->orderAttributes('shipping.parcels.*'));
        self::assertSame($this->orderAttributes('lines.*.tags'), $this->orderAttributes('lines.*.tags.*'));

        $request = $this->orderRequest(null);
        $shipping = NestedFieldsets::fieldset(OrderFieldsetResource::shipping(), ['parcels.*', 'carrier'], $request);
        self::assertNotNull($shipping);
        self::assertSame(['parcels', 'carrier'], $shipping->paths());
        self::assertNull($shipping->nested('parcels'));
        self::assertSame(['*.tags'], NestedFieldsets::fieldset(OrderFieldsetResource::lines(), ['*.tags.*'], $request)?->paths());
    }

    #[Test]
    public function aListListedWholeBesideAWildcardPathRendersWhole(): void
    {
        $whole = $this->orderAttributes('lines');

        self::assertSame($whole, $this->orderAttributes('lines,lines.*.sku'));
        self::assertSame($whole, $this->orderAttributes('lines.*.sku,lines.*'));
        self::assertSame(
            ['lines' => [['price' => ['amount' => 950, 'currency' => 'GBP']], ['price' => ['amount' => 300, 'currency' => 'EUR']], ['price' => null]]],
            $this->orderAttributes('lines.*.price,lines.*.price.amount'),
        );
    }

    #[Test]
    public function aWildcardPathContinuesIntoNestedObjectsAndLists(): void
    {
        self::assertSame(
            ['lines' => [['sku' => 'A1', 'price' => ['amount' => 950]], ['sku' => 'B2', 'price' => ['amount' => 300]], ['sku' => null, 'price' => null]]],
            $this->orderAttributes('lines.*.sku,lines.*.price.amount'),
        );
        self::assertSame(
            ['lines' => [['tags' => [['name' => 'red'], ['name' => 'sale']]], ['tags' => []], ['tags' => null]]],
            $this->orderAttributes('lines.*.tags.*.name'),
        );
    }

    #[Test]
    public function aWildcardReachesAListInsideAnObject(): void
    {
        self::assertSame(
            ['shipping' => ['parcels' => [['weight' => 2], ['weight' => 5]]]],
            $this->orderAttributes('shipping.parcels.*.weight'),
        );
    }

    #[Test]
    public function aListsExtractHookReceivesTheSelectionOfEveryItem(): void
    {
        self::assertSame(['bundles' => [['sku' => 'K1'], ['sku' => 'K2']]], $this->orderAttributes('bundles.*.sku'));
        self::assertSame([['keys' => ['*'], 'items' => ['sku'], 'paths' => ['*.sku']]], OrderFieldsetResource::$extracted);

        OrderFieldsetResource::$extracted = [];
        $this->orderAttributes('bundles');
        self::assertSame([['keys' => null, 'items' => null, 'paths' => null]], OrderFieldsetResource::$extracted);

        OrderFieldsetResource::$extracted = [];
        $this->orderAttributes('bundles.*');
        self::assertSame([['keys' => null, 'items' => null, 'paths' => null]], OrderFieldsetResource::$extracted);
    }

    #[Test]
    public function aListsSerializeHookIsTrimmedItemByItem(): void
    {
        self::assertSame(['stamped' => [['code' => 'X'], ['code' => 'Y']]], $this->orderAttributes('stamped.*.code'));
        self::assertSame([['*.code']], OrderFieldsetResource::$serialized);
    }

    #[Test]
    public function aWildcardOverAOneOfRendersTheKeyFromEachItemWhoseVariantAcceptsIt(): void
    {
        $response = $this->renderOrder('blocks.*.title');

        self::assertSame(
            ['blocks' => [['type' => 'text', 'title' => 'Intro'], ['type' => 'image'], ['type' => 'divider']]],
            $this->attributes($this->body($response)),
        );
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $this->body($response)['jsonapi']);
        self::assertSame(
            ['blocks' => [['type' => 'text', 'body' => 'Hello'], ['type' => 'image', 'url' => '/a.png'], ['type' => 'divider']]],
            $this->orderAttributes('blocks.*.url,blocks.*.body'),
        );
    }

    #[Test]
    public function aWildcardPathContinuesInsideTheVariantsThatAcceptIt(): void
    {
        self::assertSame(
            ['blocks' => [['type' => 'text', 'meta' => ['lang' => 'en']], ['type' => 'image'], ['type' => 'divider']]],
            $this->orderAttributes('blocks.*.meta.lang'),
        );
        self::assertSame(
            ['blocks' => [['type' => 'text', 'meta' => ['lang' => 'en', 'tone' => 'warm']], ['type' => 'image', 'meta' => 'raw'], ['type' => 'divider']]],
            $this->orderAttributes('blocks.*.meta,blocks.*.meta.lang'),
        );
    }

    #[Test]
    public function anUndiscriminatedItemWhoseVariantAcceptsNoSelectedKeyRendersAsAnEmptyObject(): void
    {
        $response = $this->renderOrder('cards.*.by');

        self::assertStringContainsString('"attributes":{"cards":[{"by":"Ada"},{}]}', (string) $response->getBody());
    }

    #[Test]
    public function aOneOfListSelectedWholeRendersEveryItemWhole(): void
    {
        $whole = $this->orderAttributes('blocks');

        self::assertSame($whole, $this->orderAttributes('blocks.*'));
        self::assertSame($whole, $this->orderAttributes('blocks.*,blocks.*.title'));
        self::assertIsArray($whole['blocks']);
        self::assertSame(['type' => 'image', 'title' => 'Pic', 'url' => '/a.png', 'caption' => 'A', 'meta' => 'raw'], $whole['blocks'][1]);
    }

    #[Test]
    public function aComputedOneOfListIsTrimmedPerVariantAndItsHookReceivesTheUnionFieldset(): void
    {
        self::assertSame(
            ['computedBlocks' => [['type' => 'text', 'title' => 'Intro'], ['type' => 'image', 'url' => '/a.png'], ['type' => 'divider']]],
            $this->orderAttributes('computedBlocks.*.title,computedBlocks.*.url'),
        );
        self::assertSame([['*.type', '*.title', '*.url']], OrderFieldsetResource::$computedBlocks);
    }

    #[Test]
    public function aWildcardKeyNoVariantAcceptsIsThe400(): void
    {
        $this->assertUnrecognized('blocks.*.style', ['blocks.*.style'], 'orders');
        $this->assertUnrecognized('blocks.*.nope', ['blocks.*.nope'], 'orders');
        $this->assertUnrecognized('blocks.*.title.x', ['blocks.*.title.x'], 'orders');
        $this->assertUnrecognized('blocks.*.meta.x', ['blocks.*.meta.x'], 'orders');
        $this->assertUnrecognized('plainBlocks.*', ['plainBlocks.*'], 'orders');
        $this->assertUnrecognized('plainBlocks.*.style', ['plainBlocks.*.style'], 'orders');
    }

    #[Test]
    public function theSelectionOfAOneOfListHoldsWhatAnyVariantAccepts(): void
    {
        $request = $this->orderRequest(null);
        $selection = NestedFieldsets::fieldset(OrderFieldsetResource::blocks(), ['*.title', '*.meta.lang', '*.url', '*.style'], $request);

        self::assertNotNull($selection);
        self::assertSame(['*.type', '*.title', '*.meta.lang', '*.url'], $selection->paths());

        $merged = NestedFieldsets::fieldset(OrderFieldsetResource::blocks(), ['*.meta.lang', '*.meta'], $request);
        self::assertSame(['*.type', '*.meta'], $merged?->paths());
        self::assertTrue(NestedFieldsets::narrows($this->server(), $this->orderRequest('blocks.*.url')));
        self::assertFalse(NestedFieldsets::narrows($this->server(), $this->orderRequest('blocks.*')));
    }

    #[Test]
    public function aOneOfAttributeRendersItsDiscriminatorAndTheKeysItsVariantAccepts(): void
    {
        $response = $this->renderOrder('hero.src');

        self::assertSame(['hero' => ['type' => 'image', 'src' => '/h.png']], $this->attributes($this->body($response)));
        self::assertSame(['version' => '1.1', 'profile' => [NestedFieldsetsProfile::URI]], $this->body($response)['jsonapi']);
        self::assertStringContainsString('"attributes":{"hero":{"type":"video"}}', (string) $this->renderOrder('hero.src', ['hero' => ['type' => 'video', 'url' => '/v.mp4']])->getBody());
        self::assertSame(['hero' => ['type' => 'image', 'src' => '/h.png', 'alt' => 'Hero']], $this->orderAttributes('hero,hero.src'));
        self::assertSame(['hero' => null], $this->attributes($this->body($this->renderOrder('hero.src', ['hero' => null]))));
    }

    #[Test]
    public function aOneOfChildOfAnObjectIsNarrowedThroughItsVariants(): void
    {
        self::assertSame(['layout' => ['banner' => ['type' => 'text', 'copy' => 'Hi']]], $this->orderAttributes('layout.banner.copy'));
        self::assertSame(['layout' => ['theme' => 'dark', 'banner' => ['type' => 'text', 'copy' => 'Hi', 'tone' => 'warm']]], $this->orderAttributes('layout.theme,layout.banner'));
    }

    #[Test]
    public function aOneOfsSerializeHookReceivesTheSelectionAndIsTrimmedToItsVariant(): void
    {
        OrderFieldsetResource::$promoSelections = [];

        self::assertSame(['promo' => ['type' => 'image', 'src' => '/p.png']], $this->orderAttributes('promo.src'));
        self::assertSame([['type', 'src']], OrderFieldsetResource::$promoSelections);
    }

    #[Test]
    public function aOneOfKeyNoVariantAcceptsIsThe400(): void
    {
        $this->assertUnrecognized('hero.url', ['hero.url'], 'orders');
        $this->assertUnrecognized('hero.src.x', ['hero.src.x'], 'orders');
        $this->assertUnrecognized('layout.banner.nope', ['layout.banner.nope'], 'orders');
        $this->assertUnrecognized('plainHero.x', ['plainHero.x'], 'orders');
    }

    #[Test]
    public function aWildcardOnAnythingButAListOfObjectsWithANestedFieldsetIsThe400(): void
    {
        $this->assertUnrecognized('reference.*', ['reference.*'], 'orders');
        $this->assertUnrecognized('notes.*', ['notes.*'], 'orders');
        $this->assertUnrecognized('extras.*', ['extras.*'], 'orders');
        $this->assertUnrecognized('extras.*.x', ['extras.*.x'], 'orders');
        $this->assertUnrecognized('plainLines.*', ['plainLines.*'], 'orders');
        $this->assertUnrecognized('plainLines.*.sku', ['plainLines.*.sku'], 'orders');
        $this->assertUnrecognized('lines.*.price.*', ['lines.*.price.*'], 'orders');
    }

    #[Test]
    public function anIndexSegmentIsThe400(): void
    {
        $this->assertUnrecognized('lines.0', ['lines.0'], 'orders');
        $this->assertUnrecognized('lines.0.sku', ['lines.0.sku'], 'orders');
        $this->assertUnrecognized('lines.*.tags.1.name', ['lines.*.tags.1.name'], 'orders');
    }

    #[Test]
    public function aWildcardKeyTheElementRejectsIsThe400(): void
    {
        $this->assertUnrecognized('lines.*.secret', ['lines.*.secret'], 'orders');
        $this->assertUnrecognized('lines.*.', ['lines.*.'], 'orders');
    }

    #[Test]
    public function acceptedWildcardPathsPassStrictValidation(): void
    {
        $handler = new RecordingOperationHandler(MetaResponse::fromMeta([]));
        $server = $this->server()->withHandler($handler);

        $operation = new FieldsetOperation($this->orderRequest('lines.*,lines.*.sku,lines.*.tags.*.name,shipping.parcels.*.weight'), 'orders');
        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function narrowsReadsWildcardPaths(): void
    {
        $server = $this->server();

        self::assertTrue(NestedFieldsets::narrows($server, $this->orderRequest('lines.*.sku')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->orderRequest('lines.*')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->orderRequest('lines.*,lines.*.sku')));
        self::assertTrue(NestedFieldsets::narrows($server, $this->orderRequest('shipping.parcels.*')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->orderRequest('lines,lines.*.sku')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->orderRequest('notes.*')));
    }

    #[Test]
    public function theSelectionOfAListHoldsTheSelectionOfEveryItem(): void
    {
        $lines = OrderFieldsetResource::lines();
        $request = $this->orderRequest(null);

        $selection = NestedFieldsets::fieldset($lines, ['*.sku', '*.price.amount', '*.secret', '0.sku'], $request);

        self::assertNotNull($selection);
        self::assertSame([NestedFieldset::ITEMS], $selection->keys);
        self::assertSame(['sku', 'price'], $selection->items()?->keys);
        self::assertSame(['amount'], $selection->items()->nested('price')?->keys);
        self::assertSame(['*.sku', '*.price.amount'], $selection->paths());

        self::assertNull(NestedFieldsets::fieldset($lines, ['*.sku', '*'], $request));
        self::assertTrue(NestedFieldsets::selectsWhole($lines, ['*.sku', '*'], $request));
        self::assertFalse(NestedFieldsets::selectsWhole($lines, ['*.sku'], $request));

        self::assertNull(NestedFieldsets::fieldset($lines, ['0', 'sku'], $request));
        self::assertNull((new NestedFieldset(['sku']))->items());
    }

    #[Test]
    public function aDeclarationNeedsAPredicateOrAPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Map::make('totals')->nestedFieldset();
    }

    #[Test]
    public function anInvalidPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Map::make('totals')->nestedFieldset(pattern: '[a-');
    }

    #[Test]
    public function aPatternThatCanMatchAFullStopACommaOrAnAsteriskIsRefused(): void
    {
        $refused = [
            '.' => static fn() => Obj::make('address')->fields(Str::make('a'))->nestedFieldset(pattern: '[a-z.]+'),
            ',' => static fn() => Obj::make('address')->fields(Str::make('a'))->nestedFieldset(pattern: '[^.]+'),
            '*' => static fn() => Map::make('totals')->nestedFieldset(pattern: '[a-z*]+'),
        ];
        foreach ($refused as $character => $declare) {
            try {
                $declare();
                self::fail('Expected the pattern to be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('which can match "' . $character . '"', $e->getMessage());
                self::assertStringContainsString('as [^.,*]+ does', $e->getMessage());
            }
        }

        Obj::make('address')->fields(Str::make('a'))->nestedFieldset(pattern: '[^.,*]+');
        Obj::make('address')->fields(Str::make('a'))->nestedFieldset(pattern: '[a-z]*');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function narrowsReadsTheRequestAgainstTheDeclarations(): void
    {
        $server = $this->server();

        self::assertTrue(NestedFieldsets::narrows($server, $this->request('values.cap')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->request('values,values.cap')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->request('values.Bad-Key')));
        self::assertFalse(NestedFieldsets::narrows($server, $this->request(null)));
    }

    #[Test]
    public function aNestedFieldsetKeepsANestedSelectionOnlyForAKeyItHolds(): void
    {
        $selection = new NestedFieldset(['geo'], ['geo' => new NestedFieldset(['lat']), 'other' => new NestedFieldset(['x'])]);

        self::assertSame(['lat'], $selection->nested('geo')?->keys);
        self::assertNull($selection->nested('other'));
        self::assertSame(['geo.lat'], $selection->paths());
    }

    #[Test]
    public function aNestedFieldsetHoldsEachKeyOnce(): void
    {
        $selection = new NestedFieldset(['cap', 'cap', 'region']);

        self::assertSame(['cap', 'region'], $selection->keys);
        self::assertTrue($selection->has('region'));
        self::assertFalse($selection->has('countries'));
    }

    private function server(bool $withProfile = true): Server
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(FieldsetProductResource::class)
            ->register(FieldsetVendorResource::class)
            ->register(FieldsetShelfResource::class)
            ->register(FieldsetPersonResource::class)
            ->register(OrderFieldsetResource::class);

        return $withProfile ? $server->withProfile(new NestedFieldsetsProfile()) : $server;
    }

    private function request(?string $fields, ?string $include = null, ?string $vendorFields = null): JsonApiRequestInterface
    {
        $query = [];
        if ($fields !== null) {
            $query['fields']['products'] = $fields;
        }
        if ($vendorFields !== null) {
            $query['fields']['vendors'] = $vendorFields;
        }
        if ($include !== null) {
            $query['include'] = $include;
        }

        return new JsonApiRequest((new ServerRequest('GET', '/products/1'))->withQueryParams($query));
    }

    private function render(?string $fields, ?Server $server = null, ?string $include = null, ?string $vendorFields = null): ResponseInterface
    {
        $server ??= $this->server();

        return DataResponse::fromResource(FieldsetProductResource::model(), $server->serializerFor('products'))
            ->toPsrResponse($server, $this->request($fields, $include, $vendorFields));
    }

    private function personRequest(?string $fields): JsonApiRequestInterface
    {
        return new JsonApiRequest((new ServerRequest('GET', '/people/1'))->withQueryParams($fields === null ? [] : ['fields' => ['people' => $fields]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function personAttributes(string $fields): array
    {
        $server = $this->server();

        return $this->attributes($this->body(
            DataResponse::fromResource(FieldsetPersonResource::model(), $server->serializerFor('people'))
                ->toPsrResponse($server, $this->personRequest($fields)),
        ));
    }

    private function orderRequest(?string $fields): JsonApiRequestInterface
    {
        return new JsonApiRequest((new ServerRequest('GET', '/orders/1'))->withQueryParams($fields === null ? [] : ['fields' => ['orders' => $fields]]));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function renderOrder(string $fields, array $overrides = []): ResponseInterface
    {
        $server = $this->server();

        return DataResponse::fromResource([...OrderFieldsetResource::model(), ...$overrides], $server->serializerFor('orders'))
            ->toPsrResponse($server, $this->orderRequest($fields));
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(string $fields): array
    {
        return $this->attributes($this->body($this->renderOrder($fields)));
    }

    /**
     * @param list<string> $members
     */
    private function assertUnrecognized(string $fields, array $members, string $type = 'products'): void
    {
        $server = $this->server()->withHandler(new RecordingOperationHandler(MetaResponse::fromMeta([])));

        try {
            $request = match ($type) {
                'people' => $this->personRequest($fields),
                'orders' => $this->orderRequest($fields),
                default => $this->request($fields),
            };
            $server->dispatch(new FieldsetOperation($request, $type));
            self::fail('Expected the member to be rejected.');
        } catch (FieldsetMemberUnrecognized $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame($members, $e->unrecognizedMembers);
            self::assertSame('FIELDSET_MEMBER_UNRECOGNIZED', $e->getErrors()[0]->code);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function attributes(array $body): array
    {
        self::assertIsArray($body['data']);
        $attributes = $body['data']['attributes'] ?? [];
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}

final class FieldsetProductResource extends AbstractResource
{
    public static string $type = 'products';

    /**
     * @var list<list<string>|null>
     */
    public static array $serialized = [];

    /**
     * @var list<list<string>|null>
     */
    public static array $extracted = [];

    /**
     * @return array<string, mixed>
     */
    public static function model(): array
    {
        return [
            'id' => '1',
            'name' => 'Widget',
            'values' => ['cap' => '10', 'countries' => 'GB,FR', 'region' => 'EU'],
            'groups' => ['rows' => [['a' => 1], ['a' => 2]], 'other' => [['a' => 3]]],
            'views' => 4,
            'shares' => 7,
            'hiddenUntilAsked' => ['x' => 1, 'y' => 2],
            'vendor' => ['id' => '9', 'stats' => ['rating' => 5, 'reviews' => 12], 'office' => ['city' => 'Paris', 'geo' => ['lat' => 48.8, 'lng' => 2.3]]],
        ];
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Obj::make('values')
                ->additionalProperties(Str::make('*'))
                ->nestedFieldset(pattern: '[a-z][a-z0-9_]*'),
            Obj::make('groups')
                ->additionalProperties(\haddowg\JsonApi\Resource\Field\ArrayList::make('*')->of(Obj::make('row')->fields(Integer::make('a'))))
                ->nestedFieldset(pattern: '[a-z]+'),
            Obj::make('computed')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->computedUsing(static function (array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$extracted[] = $fieldset?->keys;

                    return ['cap' => 10, 'floor' => 1];
                }),
            Map::make('totals')
                ->fields(Integer::make('views'), Integer::make('shares'))
                ->nestedFieldset(static fn(string $key): bool => $key !== 'internal'),
            Map::make('ratios')
                ->fields(Integer::make('views'), Integer::make('shares'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->serializeUsing(static function (array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$serialized[] = $fieldset?->keys;

                    return ['views' => 1, 'shares' => 2];
                }),
            Obj::make('hiddenUntilAsked')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]')
                ->sparseByDefault(),
            BelongsTo::make('vendor', 'vendors'),
        ];
    }
}

final class FieldsetVendorResource extends AbstractResource
{
    public static string $type = 'vendors';

    public function fields(): array
    {
        return [
            Id::make(),
            Obj::make('stats')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(static fn(string $key, JsonApiRequestInterface $request): bool => \in_array($key, ['rating', 'reviews'], true)),
            Obj::make('office')
                ->fields(Str::make('city'), Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->nestedFieldset(pattern: 'lat|lng'))
                ->nestedFieldset(pattern: '[a-z]+'),
        ];
    }
}

final class FieldsetPersonResource extends AbstractResource
{
    public static string $type = 'people';

    /**
     * @var list<list<string>|null>
     */
    public static array $serialized = [];

    /**
     * @var list<array{paths: list<string>|null, keys: list<string>|null, geo: list<string>|null}>
     */
    public static array $extracted = [];

    /**
     * @return array<string, mixed>
     */
    public static function model(): array
    {
        return [
            'id' => '1',
            'name' => 'Ada',
            'address' => [
                'street' => '1 High Street',
                'postcode' => 'AB1 2CD',
                'geo' => ['lat' => 51.5, 'lng' => -0.1, 'accuracy' => 'rooftop'],
                'history' => [['street' => '9 Low Road']],
            ],
            'scores' => ['maths' => ['min' => 4, 'max' => 9], 'art' => ['min' => 2, 'max' => 7]],
            'phone' => ['home' => '0100', 'work' => '0200'],
            'email' => 'ada@example.com',
        ];
    }

    public static function address(): Obj
    {
        return Obj::make('address')->fields(
            Str::make('street'),
            Str::make('postcode'),
            Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'), Str::make('accuracy'))->nestedFieldset(pattern: 'lat|lng|accuracy'),
            Obj::make('Region')->fields(Str::make('code'))->nestedFieldset(pattern: '[a-z]+'),
            Obj::make('plain')->fields(Str::make('code')),
            \haddowg\JsonApi\Resource\Field\ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('street'))),
        )->nestedFieldset(pattern: '[a-z]+')->build();
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            self::address(),
            Obj::make('scores')
                ->additionalProperties(Obj::make('*')->fields(Integer::make('min'), Integer::make('max'))->nestedFieldset(pattern: 'min|max'))
                ->nestedFieldset(pattern: '[a-z]+'),
            Map::make('contact')
                ->fields(Str::make('email'), Obj::make('phone')->fields(Str::make('home'), Str::make('work'))->nestedFieldset(pattern: 'home|work'))
                ->nestedFieldset(pattern: '[a-z]+'),
            Obj::make('office')
                ->fields(Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->nestedFieldset(pattern: 'lat|lng'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->computedUsing(static function (array $person, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$extracted[] = ['paths' => $fieldset?->paths(), 'keys' => $fieldset?->keys, 'geo' => $fieldset?->nested('geo')?->keys];

                    return ['geo' => ['lat' => 51.4, 'lng' => -0.2]];
                }),
            Obj::make('badge')
                ->fields(Obj::make('colour')->fields(Str::make('fg'), Str::make('bg'))->nestedFieldset(pattern: '[a-z]+'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->serializeUsing(static function (array $person, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$serialized[] = $fieldset?->paths();

                    return ['colour' => ['fg' => 'black', 'bg' => 'white'], 'shape' => 'round'];
                }),
        ];
    }
}

final class OrderFieldsetResource extends AbstractResource
{
    /**
     * @var list<list<string>|null>
     */
    public static array $serialized = [];

    public static string $type = 'orders';

    /**
     * @var list<list<string>|null>
     */
    public static array $computedBlocks = [];

    /**
     * @var list<list<string>|null>
     */
    public static array $promoSelections = [];

    /**
     * @var list<array{keys: list<string>|null, items: list<string>|null, paths: list<string>|null}>
     */
    public static array $extracted = [];

    /**
     * @return array<string, mixed>
     */
    public static function model(): array
    {
        return [
            'id' => '1',
            'reference' => 'ORD-1',
            'lines' => [
                ['sku' => 'A1', 'qty' => 2, 'price' => ['amount' => 950, 'currency' => 'GBP'], 'tags' => [['name' => 'red', 'colour' => 'f00'], ['name' => 'sale', 'colour' => '0f0']]],
                ['sku' => 'B2', 'qty' => 1, 'price' => ['amount' => 300, 'currency' => 'EUR'], 'tags' => []],
                ['qty' => 5],
            ],
            'plainLines' => [['sku' => 'P1']],
            'notes' => ['fragile'],
            'extras' => ['gift' => 'yes'],
            'cells' => [['a' => '1', 'b' => '2'], ['b' => '3']],
            'shipping' => ['carrier' => 'post', 'parcels' => [['weight' => 2, 'label' => 'a'], ['weight' => 5, 'label' => 'b']]],
            'archived' => [['sku' => 'Z9', 'qty' => 4]],
            'blocks' => self::blockValues(),
            'plainBlocks' => [['type' => 'a', 'style' => 'x']],
            'cards' => [['quote' => 'Be brief', 'by' => 'Ada'], ['href' => '/x', 'label' => 'X']],
            'hero' => ['type' => 'image', 'src' => '/h.png', 'alt' => 'Hero'],
            'layout' => ['theme' => 'dark', 'banner' => ['type' => 'text', 'copy' => 'Hi', 'tone' => 'warm']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function blockValues(): array
    {
        return [
            ['type' => 'text', 'title' => 'Intro', 'body' => 'Hello', 'meta' => ['lang' => 'en', 'tone' => 'warm']],
            ['type' => 'image', 'title' => 'Pic', 'url' => '/a.png', 'caption' => 'A', 'meta' => 'raw'],
            ['type' => 'divider', 'style' => 'dashed'],
        ];
    }

    public static function blocks(string $name = 'blocks'): ArrayList
    {
        return self::blocksBuilder($name)->build();
    }

    private static function blocksBuilder(string $name): \haddowg\JsonApi\Resource\Field\ArrayListBuilder
    {
        return ArrayList::make($name)->of(
            OneOf::make('block')->discriminator('type')
                ->variant('text', Obj::make('text')->fields(Str::make('title'), Str::make('body'), Obj::make('meta')->fields(Str::make('lang'), Str::make('tone'))->nestedFieldset(pattern: 'lang|tone'))->nestedFieldset(pattern: 'title|body|meta'))
                ->variant('image', Obj::make('image')->fields(Str::make('title'), Str::make('url'), Str::make('caption'), Str::make('meta'))->nestedFieldset(pattern: 'url|caption|meta'))
                ->variant('divider', Str::make('style')),
        );
    }

    public static function lines(): ArrayList
    {
        return ArrayList::make('lines')->of(
            Obj::make('line')->fields(
                Str::make('sku'),
                Integer::make('qty'),
                Obj::make('price')->fields(Integer::make('amount'), Str::make('currency'))->nestedFieldset(pattern: 'amount|currency'),
                ArrayList::make('tags')->of(Obj::make('tag')->fields(Str::make('name'), Str::make('colour'))->nestedFieldset(pattern: 'name|colour')),
            )->nestedFieldset(pattern: 'sku|qty|price|tags'),
        )->build();
    }

    public static function shipping(): Obj
    {
        return Obj::make('shipping')->fields(
            Str::make('carrier'),
            ArrayList::make('parcels')->of(Obj::make('parcel')->fields(Integer::make('weight'), Str::make('label'))->nestedFieldset(pattern: 'weight|label')),
        )->nestedFieldset(pattern: '[a-z]+')->build();
    }

    public function fields(): array
    {
        $line = Obj::make('line')->fields(Str::make('sku'), Integer::make('qty'))->nestedFieldset(pattern: '[a-z]+');

        return [
            Id::make(),
            Str::make('reference'),
            self::lines(),
            ArrayList::make('plainLines')->of(Obj::make('line')->fields(Str::make('sku'))),
            ArrayList::make('notes'),
            Obj::make('extras')->additionalProperties(Str::make('*'))->nestedFieldset(static fn(string $key): bool => true),
            ArrayList::make('cells')->of(Obj::make('cell')->additionalProperties(Str::make('*'))->nestedFieldset(pattern: '[a-z]+')),
            self::shipping(),
            ArrayList::make('archived')->of($line)->sparseByDefault(),
            self::blocks(),
            OneOf::make('plainHero')->nullable()->discriminator('type')->variant('a', Str::make('x')),
            ArrayList::make('plainBlocks')->of(OneOf::make('block')->discriminator('type')->variant('a', Str::make('style'))),
            ArrayList::make('cards')->of(
                OneOf::make('card')
                    ->variant('quote', Obj::make('quote')->fields(Str::make('quote')->required(), Str::make('by'))->nestedFieldset(pattern: 'by'))
                    ->variant('link', Str::make('href')->required(), Str::make('label')),
            ),
            OneOf::make('hero')->nullable()->discriminator('type')
                ->variant('image', Obj::make('image')->fields(Str::make('src'), Str::make('alt'))->nestedFieldset(pattern: 'src|alt'))
                ->variant('video', Str::make('url')),
            Obj::make('layout')->fields(
                Str::make('theme'),
                OneOf::make('banner')->discriminator('type')
                    ->variant('text', Obj::make('text')->fields(Str::make('copy'), Str::make('tone'))->nestedFieldset(pattern: 'copy|tone')),
            )->nestedFieldset(pattern: '[a-z]+'),
            OneOf::make('promo')->discriminator('type')
                ->variant('image', Obj::make('image')->fields(Str::make('src'), Str::make('alt'))->nestedFieldset(pattern: 'src|alt'))
                ->serializeUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$promoSelections[] = $fieldset?->paths();

                    return ['type' => 'image', 'src' => '/p.png', 'alt' => 'Promo', 'extra' => 'x'];
                }),
            self::blocksBuilder('computedBlocks')->computedUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                self::$computedBlocks[] = $fieldset?->paths();

                return self::blockValues();
            }),
            ArrayList::make('bundles')->of($line)->computedUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                self::$extracted[] = ['keys' => $fieldset?->keys, 'items' => $fieldset?->items()?->keys, 'paths' => $fieldset?->paths()];

                return [['sku' => 'K1', 'qty' => 1], ['sku' => 'K2', 'qty' => 2]];
            }),
            ArrayList::make('stamped')->of(Obj::make('stamp')->fields(Str::make('code'), Str::make('by'))->nestedFieldset(pattern: '[a-z]+'))
                ->serializeUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array {
                    self::$serialized[] = $fieldset?->paths();

                    return [['code' => 'X', 'by' => 'ann'], ['code' => 'Y', 'by' => 'bob']];
                }),
        ];
    }
}

final class FieldsetShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            Obj::make('pinned')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]')
                ->notSparseField(),
        ];
    }
}

final readonly class FieldsetOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
{
    public function __construct(private JsonApiRequestInterface $request, private string $type = 'products') {}

    public function target(): \haddowg\JsonApi\Operation\Target
    {
        return new \haddowg\JsonApi\Operation\Target($this->type);
    }

    public function queryParameters(): \haddowg\JsonApi\Operation\QueryParameters
    {
        return \haddowg\JsonApi\Operation\QueryParameters::fromRequest($this->request);
    }

    public function context(): \haddowg\JsonApi\Operation\OperationContext
    {
        return new \haddowg\JsonApi\Operation\OperationContext(new StubServer(), $this->request);
    }
}
