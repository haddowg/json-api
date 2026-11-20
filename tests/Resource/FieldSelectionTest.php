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
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\FieldSelection;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Profile\FieldSelectionProfile;
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

#[CoversClass(FieldSelection::class)]
#[CoversClass(KeySelection::class)]
#[CoversClass(\haddowg\JsonApi\Resource\Field\SelectableKeys::class)]
#[CoversClass(FieldSelectionProfile::class)]
#[Group('spec:sparse-fieldsets')]
#[Group('spec:extensions-and-profiles')]
final class FieldSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        SelectionProductResource::$extracted = [];
        SelectionPersonResource::$extracted = [];
        OrderSelectionResource::$extracted = [];
        SelectionProductResource::$serialized = [];
        SelectionPersonResource::$serialized = [];
        OrderSelectionResource::$serialized = [];
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

        self::assertSame('application/vnd.api+json; profile="' . FieldSelectionProfile::URI . '"', $response->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $response->getHeaderLine('Vary'));
        self::assertSame(['version' => '1.1', 'profile' => [FieldSelectionProfile::URI]], $this->body($response)['jsonapi']);
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
        self::assertSame([['cap']], SelectionProductResource::$extracted);
    }

    #[Test]
    public function anExtractHookReceivesNullWhenTheAttributeIsNotNarrowed(): void
    {
        $this->render('computed');

        self::assertSame([null], SelectionProductResource::$extracted);
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
        self::assertSame(['version' => '1.1', 'profile' => [FieldSelectionProfile::URI]], $body['jsonapi']);
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
        self::assertStringContainsString(FieldSelectionProfile::URI, $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anAtomicResultsResponseAdvertisesTheProfileWhenNarrowed(): void
    {
        $server = $this->server();
        $response = AtomicResultsResponse::fromResults([AtomicResult::empty()])
            ->toPsrResponse($server, $this->request('values.cap'));

        self::assertStringContainsString('profile="' . FieldSelectionProfile::URI . '"', $response->getHeaderLine('Content-Type'));
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
    public function aDottedMemberOnAnAttributeWithoutSelectableKeysIsThe400(): void
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

        // `rows.a` is a path into `rows`, a list whose element declares no selectable keys.
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

        $operation = new SelectionOperation($this->request('name,values.cap,totals.views'));
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
    public function anExtractHookReceivesTheNestedSelection(): void
    {
        self::assertSame(['office' => ['geo' => ['lng' => -0.2]]], $this->personAttributes('office.geo.lng'));
        self::assertSame([['paths' => ['geo.lng'], 'keys' => ['geo'], 'geo' => ['lng']]], SelectionPersonResource::$extracted);
    }

    #[Test]
    public function aSerializeHooksValueIsTrimmedAtEveryDepth(): void
    {
        self::assertSame(['badge' => ['colour' => ['fg' => 'black']]], $this->personAttributes('badge.colour.fg'));
        self::assertSame([['colour.fg']], SelectionPersonResource::$serialized);
    }

    #[Test]
    public function aSerializeHookReceivesNullWhenTheAttributeIsNotNarrowed(): void
    {
        $this->personAttributes('badge');
        $this->render('ratios');

        self::assertSame([null], SelectionPersonResource::$serialized);
        self::assertSame([null], SelectionProductResource::$serialized);
    }

    #[Test]
    public function aMapsSerializeHookReceivesTheSelectionAndIsTrimmed(): void
    {
        $body = $this->body($this->render('ratios.views'));

        self::assertSame(['ratios' => ['views' => 1]], $this->attributes($body));
        self::assertSame([['views']], SelectionProductResource::$serialized);
    }

    #[Test]
    public function aNestedSelectionAppliesToAnIncludedResource(): void
    {
        $body = $this->body($this->render('name,vendor', include: 'vendor', vendorFields: 'office.geo.lat'));

        self::assertIsArray($body['included']);
        self::assertIsArray($body['included'][0]);
        self::assertSame(['office' => ['geo' => ['lat' => 48.8]]], $body['included'][0]['attributes']);
        self::assertSame(['version' => '1.1', 'profile' => [FieldSelectionProfile::URI]], $body['jsonapi']);
    }

    #[Test]
    public function aNestedKeyTheObjectRejectsIsThe400(): void
    {
        $this->assertUnrecognized('address.geo.alt', ['address.geo.alt'], 'people');
        $this->assertUnrecognized('address.Region.code', ['address.Region.code'], 'people');
    }

    #[Test]
    public function aPathBelowAKeyWhoseObjectDeclaresNoSelectableKeysIsThe400(): void
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

        $operation = new SelectionOperation($this->personRequest('address.geo.lat,scores.maths.max,contact.phone.work'), 'people');
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

        self::assertTrue(FieldSelection::narrows($server, $this->personRequest('address.geo.lat')));
        self::assertFalse(FieldSelection::narrows($server, $this->personRequest('address.geo.alt')));
        self::assertFalse(FieldSelection::narrows($server, $this->personRequest('address,address.geo.lat')));
    }

    #[Test]
    public function theSelectionHoldsANestedSelectionPerNarrowedKey(): void
    {
        $address = SelectionPersonResource::address();
        $request = $this->personRequest(null);

        $selection = FieldSelection::selection($address, ['postcode', 'geo.lat', 'geo.lng', 'geo.alt'], $request);

        self::assertNotNull($selection);
        self::assertSame(['postcode', 'geo'], $selection->keys);
        self::assertNull($selection->nested('postcode'));
        self::assertSame(['lat', 'lng'], $selection->nested('geo')?->keys);
        self::assertSame(['postcode', 'geo.lat', 'geo.lng'], $selection->paths());

        $whole = FieldSelection::selection($address, ['geo.lat', 'geo'], $request);
        self::assertNotNull($whole);
        self::assertSame(['geo'], $whole->keys);
        self::assertNull($whole->nested('geo'));

        self::assertNull(FieldSelection::selection($address, ['geo.alt', 'postcode.x'], $request));
    }

    #[Test]
    public function aWildcardSelectsTheKeyFromEveryItemKeepingTheListsLengthAndOrder(): void
    {
        $response = $this->renderOrder('lines.*.sku');

        self::assertSame(['lines' => [['sku' => 'A1'], ['sku' => 'B2'], ['sku' => null]]], $this->attributes($this->body($response)));
        self::assertSame(['version' => '1.1', 'profile' => [FieldSelectionProfile::URI]], $this->body($response)['jsonapi']);
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
        $shipping = FieldSelection::selection(OrderSelectionResource::shipping(), ['parcels.*', 'carrier'], $request);
        self::assertNotNull($shipping);
        self::assertSame(['parcels', 'carrier'], $shipping->paths());
        self::assertNull($shipping->nested('parcels'));
        self::assertSame(['*.tags'], FieldSelection::selection(OrderSelectionResource::lines(), ['*.tags.*'], $request)?->paths());
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
        self::assertSame([['keys' => ['*'], 'items' => ['sku'], 'paths' => ['*.sku']]], OrderSelectionResource::$extracted);

        OrderSelectionResource::$extracted = [];
        $this->orderAttributes('bundles');
        self::assertSame([['keys' => null, 'items' => null, 'paths' => null]], OrderSelectionResource::$extracted);

        OrderSelectionResource::$extracted = [];
        $this->orderAttributes('bundles.*');
        self::assertSame([['keys' => null, 'items' => null, 'paths' => null]], OrderSelectionResource::$extracted);
    }

    #[Test]
    public function aListsSerializeHookIsTrimmedItemByItem(): void
    {
        self::assertSame(['stamped' => [['code' => 'X'], ['code' => 'Y']]], $this->orderAttributes('stamped.*.code'));
        self::assertSame([['*.code']], OrderSelectionResource::$serialized);
    }

    #[Test]
    public function aWildcardOnAnythingButAListOfSelectableObjectsIsThe400(): void
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

        $operation = new SelectionOperation($this->orderRequest('lines.*,lines.*.sku,lines.*.tags.*.name,shipping.parcels.*.weight'), 'orders');
        $server->dispatch($operation);

        self::assertSame($operation, $handler->received);
    }

    #[Test]
    public function narrowsReadsWildcardPaths(): void
    {
        $server = $this->server();

        self::assertTrue(FieldSelection::narrows($server, $this->orderRequest('lines.*.sku')));
        self::assertFalse(FieldSelection::narrows($server, $this->orderRequest('lines.*')));
        self::assertFalse(FieldSelection::narrows($server, $this->orderRequest('lines.*,lines.*.sku')));
        self::assertTrue(FieldSelection::narrows($server, $this->orderRequest('shipping.parcels.*')));
        self::assertFalse(FieldSelection::narrows($server, $this->orderRequest('lines,lines.*.sku')));
        self::assertFalse(FieldSelection::narrows($server, $this->orderRequest('notes.*')));
    }

    #[Test]
    public function theSelectionOfAListHoldsTheSelectionOfEveryItem(): void
    {
        $lines = OrderSelectionResource::lines();
        $request = $this->orderRequest(null);

        $selection = FieldSelection::selection($lines, ['*.sku', '*.price.amount', '*.secret', '0.sku'], $request);

        self::assertNotNull($selection);
        self::assertSame([KeySelection::ITEMS], $selection->keys);
        self::assertSame(['sku', 'price'], $selection->items()?->keys);
        self::assertSame(['amount'], $selection->items()->nested('price')?->keys);
        self::assertSame(['*.sku', '*.price.amount'], $selection->paths());

        self::assertNull(FieldSelection::selection($lines, ['*.sku', '*'], $request));
        self::assertTrue(FieldSelection::selectsWhole($lines, ['*.sku', '*'], $request));
        self::assertFalse(FieldSelection::selectsWhole($lines, ['*.sku'], $request));

        self::assertNull(FieldSelection::selection($lines, ['0', 'sku'], $request));
        self::assertNull((new KeySelection(['sku']))->items());
    }

    #[Test]
    public function aDeclarationNeedsAPredicateOrAPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Map::make('totals')->selectableKeys();
    }

    #[Test]
    public function anInvalidPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Map::make('totals')->selectableKeys(pattern: '[a-');
    }

    #[Test]
    public function aPatternThatCanMatchAFullStopIsRefused(): void
    {
        foreach ([static fn() => Obj::make('address')->fields(Str::make('a'))->selectableKeys(pattern: '[a-z.]+'), static fn() => Map::make('totals')->selectableKeys(pattern: '.+')] as $declare) {
            try {
                $declare();
                self::fail('Expected the pattern to be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('which can match a full stop', $e->getMessage());
            }
        }

        Obj::make('address')->fields(Str::make('a'))->selectableKeys(pattern: '[^.,]+');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function narrowsReadsTheRequestAgainstTheDeclarations(): void
    {
        $server = $this->server();

        self::assertTrue(FieldSelection::narrows($server, $this->request('values.cap')));
        self::assertFalse(FieldSelection::narrows($server, $this->request('values,values.cap')));
        self::assertFalse(FieldSelection::narrows($server, $this->request('values.Bad-Key')));
        self::assertFalse(FieldSelection::narrows($server, $this->request(null)));
    }

    #[Test]
    public function aKeySelectionKeepsANestedSelectionOnlyForAKeyItHolds(): void
    {
        $selection = new KeySelection(['geo'], ['geo' => new KeySelection(['lat']), 'other' => new KeySelection(['x'])]);

        self::assertSame(['lat'], $selection->nested('geo')?->keys);
        self::assertNull($selection->nested('other'));
        self::assertSame(['geo.lat'], $selection->paths());
    }

    #[Test]
    public function aKeySelectionHoldsEachKeyOnce(): void
    {
        $selection = new KeySelection(['cap', 'cap', 'region']);

        self::assertSame(['cap', 'region'], $selection->keys);
        self::assertTrue($selection->has('region'));
        self::assertFalse($selection->has('countries'));
    }

    private function server(bool $withProfile = true): Server
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(SelectionProductResource::class)
            ->register(SelectionVendorResource::class)
            ->register(SelectionShelfResource::class)
            ->register(SelectionPersonResource::class)
            ->register(OrderSelectionResource::class);

        return $withProfile ? $server->withProfile(new FieldSelectionProfile()) : $server;
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

        return DataResponse::fromResource(SelectionProductResource::model(), $server->serializerFor('products'))
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
            DataResponse::fromResource(SelectionPersonResource::model(), $server->serializerFor('people'))
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

        return DataResponse::fromResource([...OrderSelectionResource::model(), ...$overrides], $server->serializerFor('orders'))
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
            $server->dispatch(new SelectionOperation($request, $type));
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

final class SelectionProductResource extends AbstractResource
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
                ->selectableKeys(pattern: '[a-z][a-z0-9_]*'),
            Obj::make('groups')
                ->additionalProperties(\haddowg\JsonApi\Resource\Field\ArrayList::make('*')->of(Obj::make('row')->fields(Integer::make('a'))))
                ->selectableKeys(pattern: '[a-z]+'),
            Obj::make('computed')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(pattern: '[a-z]+')
                ->computedUsing(static function (array $row, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$extracted[] = $only?->keys;

                    return ['cap' => 10, 'floor' => 1];
                }),
            Map::make('totals')
                ->fields(Integer::make('views'), Integer::make('shares'))
                ->selectableKeys(static fn(string $key): bool => $key !== 'internal'),
            Map::make('ratios')
                ->fields(Integer::make('views'), Integer::make('shares'))
                ->selectableKeys(pattern: '[a-z]+')
                ->serializeUsing(static function (array $row, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$serialized[] = $only?->keys;

                    return ['views' => 1, 'shares' => 2];
                }),
            Obj::make('hiddenUntilAsked')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(pattern: '[a-z]')
                ->sparseByDefault(),
            BelongsTo::make('vendor', 'vendors'),
        ];
    }
}

final class SelectionVendorResource extends AbstractResource
{
    public static string $type = 'vendors';

    public function fields(): array
    {
        return [
            Id::make(),
            Obj::make('stats')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(static fn(string $key, JsonApiRequestInterface $request): bool => \in_array($key, ['rating', 'reviews'], true)),
            Obj::make('office')
                ->fields(Str::make('city'), Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->selectableKeys(pattern: 'lat|lng'))
                ->selectableKeys(pattern: '[a-z]+'),
        ];
    }
}

final class SelectionPersonResource extends AbstractResource
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
            Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'), Str::make('accuracy'))->selectableKeys(pattern: 'lat|lng|accuracy'),
            Obj::make('Region')->fields(Str::make('code'))->selectableKeys(pattern: '[a-z]+'),
            Obj::make('plain')->fields(Str::make('code')),
            \haddowg\JsonApi\Resource\Field\ArrayList::make('history')->of(Obj::make('entry')->fields(Str::make('street'))),
        )->selectableKeys(pattern: '[a-z]+')->build();
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            self::address(),
            Obj::make('scores')
                ->additionalProperties(Obj::make('*')->fields(Integer::make('min'), Integer::make('max'))->selectableKeys(pattern: 'min|max'))
                ->selectableKeys(pattern: '[a-z]+'),
            Map::make('contact')
                ->fields(Str::make('email'), Obj::make('phone')->fields(Str::make('home'), Str::make('work'))->selectableKeys(pattern: 'home|work'))
                ->selectableKeys(pattern: '[a-z]+'),
            Obj::make('office')
                ->fields(Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng'))->selectableKeys(pattern: 'lat|lng'))
                ->selectableKeys(pattern: '[a-z]+')
                ->computedUsing(static function (array $person, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$extracted[] = ['paths' => $only?->paths(), 'keys' => $only?->keys, 'geo' => $only?->nested('geo')?->keys];

                    return ['geo' => ['lat' => 51.4, 'lng' => -0.2]];
                }),
            Obj::make('badge')
                ->fields(Obj::make('colour')->fields(Str::make('fg'), Str::make('bg'))->selectableKeys(pattern: '[a-z]+'))
                ->selectableKeys(pattern: '[a-z]+')
                ->serializeUsing(static function (array $person, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$serialized[] = $only?->paths();

                    return ['colour' => ['fg' => 'black', 'bg' => 'white'], 'shape' => 'round'];
                }),
        ];
    }
}

final class OrderSelectionResource extends AbstractResource
{
    /**
     * @var list<list<string>|null>
     */
    public static array $serialized = [];

    public static string $type = 'orders';

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
        ];
    }

    public static function lines(): ArrayList
    {
        return ArrayList::make('lines')->of(
            Obj::make('line')->fields(
                Str::make('sku'),
                Integer::make('qty'),
                Obj::make('price')->fields(Integer::make('amount'), Str::make('currency'))->selectableKeys(pattern: 'amount|currency'),
                ArrayList::make('tags')->of(Obj::make('tag')->fields(Str::make('name'), Str::make('colour'))->selectableKeys(pattern: 'name|colour')),
            )->selectableKeys(pattern: 'sku|qty|price|tags'),
        )->build();
    }

    public static function shipping(): Obj
    {
        return Obj::make('shipping')->fields(
            Str::make('carrier'),
            ArrayList::make('parcels')->of(Obj::make('parcel')->fields(Integer::make('weight'), Str::make('label'))->selectableKeys(pattern: 'weight|label')),
        )->selectableKeys(pattern: '[a-z]+')->build();
    }

    public function fields(): array
    {
        $line = Obj::make('line')->fields(Str::make('sku'), Integer::make('qty'))->selectableKeys(pattern: '[a-z]+');

        return [
            Id::make(),
            Str::make('reference'),
            self::lines(),
            ArrayList::make('plainLines')->of(Obj::make('line')->fields(Str::make('sku'))),
            ArrayList::make('notes'),
            Obj::make('extras')->additionalProperties(Str::make('*'))->selectableKeys(static fn(string $key): bool => true),
            ArrayList::make('cells')->of(Obj::make('cell')->additionalProperties(Str::make('*'))->selectableKeys(pattern: '[a-z]+')),
            self::shipping(),
            ArrayList::make('archived')->of($line)->sparseByDefault(),
            ArrayList::make('bundles')->of($line)->computedUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                self::$extracted[] = ['keys' => $only?->keys, 'items' => $only?->items()?->keys, 'paths' => $only?->paths()];

                return [['sku' => 'K1', 'qty' => 1], ['sku' => 'K2', 'qty' => 2]];
            }),
            ArrayList::make('stamped')->of(Obj::make('stamp')->fields(Str::make('code'), Str::make('by'))->selectableKeys(pattern: '[a-z]+'))
                ->serializeUsing(static function (array $order, JsonApiRequestInterface $request, string $name, ?KeySelection $only): array {
                    self::$serialized[] = $only?->paths();

                    return [['code' => 'X', 'by' => 'ann'], ['code' => 'Y', 'by' => 'bob']];
                }),
        ];
    }
}

final class SelectionShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            Obj::make('pinned')
                ->additionalProperties(Integer::make('*'))
                ->selectableKeys(pattern: '[a-z]')
                ->notSparseField(),
        ];
    }
}

final readonly class SelectionOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
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
