<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Exception\FieldsetMemberUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
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

        // A pattern admitting dots takes `rows.a` whole as one key, which no member has.
        $body = $this->body($this->render('name,groups.rows.a'));
        self::assertSame(['name' => 'Widget', 'groups' => []], $this->attributes($body));
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
    public function aClosedObjectCannotDeclareSelectableKeys(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('selectableKeys()');

        Obj::make('closed')->fields(Str::make('a'))->selectableKeys(pattern: '[a-z]+')->build();
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
    public function narrowsReadsTheRequestAgainstTheDeclarations(): void
    {
        $server = $this->server();

        self::assertTrue(FieldSelection::narrows($server, $this->request('values.cap')));
        self::assertFalse(FieldSelection::narrows($server, $this->request('values,values.cap')));
        self::assertFalse(FieldSelection::narrows($server, $this->request('values.Bad-Key')));
        self::assertFalse(FieldSelection::narrows($server, $this->request(null)));
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
            ->register(SelectionVendorResource::class);

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

    /**
     * @param list<string> $members
     */
    private function assertUnrecognized(string $fields, array $members): void
    {
        $server = $this->server()->withHandler(new RecordingOperationHandler(MetaResponse::fromMeta([])));

        try {
            $server->dispatch(new SelectionOperation($this->request($fields)));
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
            'vendor' => ['id' => '9', 'stats' => ['rating' => 5, 'reviews' => 12]],
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
                ->selectableKeys(pattern: '[a-z.]+'),
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
        ];
    }
}

final readonly class SelectionOperation implements \haddowg\JsonApi\Operation\JsonApiOperationInterface
{
    public function __construct(private JsonApiRequestInterface $request) {}

    public function target(): \haddowg\JsonApi\Operation\Target
    {
        return new \haddowg\JsonApi\Operation\Target('products');
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
