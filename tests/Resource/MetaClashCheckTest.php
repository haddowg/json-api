<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\DocumentMetaMismatch;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\RenderedMeta;
use haddowg\JsonApi\Resource\ResourceMetaMismatch;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenderedMeta::class)]
#[CoversClass(MetaShape::class)]
#[CoversClass(DocumentMeta::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(AbstractRelationValue::class)]
final class MetaClashCheckTest extends TestCase
{
    #[Test]
    public function aRequestIsMarkedForTheCheck(): void
    {
        self::assertFalse(RenderedMeta::checks(self::request()));
        self::assertTrue(RenderedMeta::checks(RenderedMeta::checking(self::request())));
    }

    #[Test]
    public function aDeclaredResourceMetaMemberWinsOverTheEntry(): void
    {
        self::assertSame(['nights' => 3, 'source' => 'web'], self::resourceMeta()->extract(['nights' => 3], self::request()));
    }

    #[Test]
    public function theCheckRefusesAnEntryReturningADeclaredResourceMetaMember(): void
    {
        $this->expectException(ResourceMetaMismatch::class);
        $this->expectExceptionMessage('The additionalProperties entry of the "bookings" resource object read returned the member "nights", which a declared member of the same name replaced.');

        self::resourceMeta()->extract(['nights' => 3], RenderedMeta::checking(self::request()));
    }

    #[Test]
    public function aDeclaredDocumentMetaMemberWinsOverTheEntry(): void
    {
        self::assertSame(['total' => 2, 'cursor' => 'c1'], self::documentMeta()->extract([], self::request()));
    }

    #[Test]
    public function theCheckRefusesAnEntryReturningADeclaredDocumentMetaMember(): void
    {
        $this->expectException(DocumentMetaMismatch::class);
        $this->expectExceptionMessage('returned the member "total"');

        self::documentMeta()->extract([], RenderedMeta::checking(self::request()));
    }

    #[Test]
    public function aDeclaredPivotFieldWinsOverTheEntry(): void
    {
        self::assertSame(['view' => 'sea'], self::pivot()->hookedPivotValues(['id' => 'b1'], ['id' => 'r1'], ['nights' => 2], self::request()));
    }

    #[Test]
    public function theCheckRefusesAPivotEntryReturningADeclaredPivotField(): void
    {
        $this->expectException(ResourceMetaMismatch::class);
        $this->expectExceptionMessage('The additionalProperties entry of the pivot of relation "rooms" returned the member "nights"');

        self::pivot()->hookedPivotValues(['id' => 'b1'], ['id' => 'r1'], ['nights' => 2], RenderedMeta::checking(self::request()));
    }

    #[Test]
    public function aPivotRelationsPivotWinsOverItsIdentifierMetaResolver(): void
    {
        self::assertSame(['view' => 'sea'], self::identifierMeta(self::request()));
    }

    #[Test]
    public function theCheckRefusesAnIdentifierMetaResolverReturningPivot(): void
    {
        $this->expectException(ResourceMetaMismatch::class);
        $this->expectExceptionMessage('The identifierMeta() resolver of the identifier meta of relation "rooms" returned the member "pivot"');

        self::identifierMeta(RenderedMeta::checking(self::request()));
    }

    #[Test]
    public function aRelationWithoutAPivotLeavesItsResolversPivotAlone(): void
    {
        $relation = BelongsTo::make('guest', 'users')
            ->identifierMeta(static fn(): array => ['pivot' => 'free'])
            ->build();

        self::assertSame(['pivot' => 'free'], self::renderedIdentifierMeta($relation, ['id' => 'b1', 'guest' => ['id' => 'u1']], RenderedMeta::checking(self::request())));
    }

    private static function resourceMeta(): MetaShape
    {
        return MetaShape::of([
            Integer::make('nights')->readOnly()->extractUsing(static fn(array $booking): mixed => $booking['nights']),
            AdditionalProperties::make()->extractUsing(static fn(): array => ['nights' => 99, 'source' => 'web']),
        ], 'the "bookings" resource object read');
    }

    private static function documentMeta(): DocumentMeta
    {
        return new DocumentMeta([
            Integer::make('total')->extractUsing(static fn(): int => 2),
            AdditionalProperties::make()->extractUsing(static fn(): array => ['total' => 5, 'cursor' => 'c1']),
        ]);
    }

    private static function pivot(): BelongsToMany
    {
        return BelongsToMany::make('rooms', 'rooms')->fields(
            Integer::make('nights'),
            AdditionalProperties::make()->extractUsing(static fn(PivotEdge $edge): array => ['view' => 'sea', 'nights' => 9]),
        )->build();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function identifierMeta(JsonApiRequestInterface $request): ?array
    {
        $relation = BelongsToMany::make('rooms', 'users')
            ->fields(Integer::make('nights'))
            ->identifierMeta(static fn(): array => ['pivot' => 'shadow', 'view' => 'sea'], [Str::make('view')])
            ->build();

        $first = self::renderedIdentifierMeta($relation, ['id' => 'b1', 'rooms' => [['id' => 'u1']]], $request)[0] ?? null;

        return \is_array($first) ? $first : null;
    }

    /**
     * The `meta` of the rendered linkage: one identifier's for a to-one, a list of them for a to-many.
     *
     * @param array<string, mixed> $model
     *
     * @return array<array-key, mixed>|null
     */
    private static function renderedIdentifierMeta(AbstractRelationValue $relation, array $model, JsonApiRequestInterface $request): ?array
    {
        $object = (array) $relation->buildRelationship($model, $request, new StubSerializerResolver())->transform(
            new ResourceTransformation(new StubResource('bookings', 'b1'), $model, 'bookings', $request, '', '', $relation->name(), 'https://api.example.com'),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );
        $data = $object['data'] ?? null;
        if (!\is_array($data)) {
            return null;
        }
        if (\array_is_list($data)) {
            return \array_map(static fn(mixed $identifier): mixed => \is_array($identifier) ? ($identifier['meta'] ?? null) : null, $data);
        }
        $meta = $data['meta'] ?? null;

        return \is_array($meta) ? $meta : null;
    }

    private static function request(): JsonApiRequestInterface
    {
        return new JsonApiRequest(new ServerRequest('GET', '/bookings'));
    }
}
