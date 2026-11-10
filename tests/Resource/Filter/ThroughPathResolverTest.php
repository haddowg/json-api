<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\ListValue;
use haddowg\JsonApi\Resource\Filter\ThroughPath;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Filter\WhereThroughInBuilder;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThroughPathResolver::class)]
#[CoversClass(ThroughPath::class)]
#[CoversClass(WhereThroughIn::class)]
#[CoversClass(WhereThroughInBuilder::class)]
#[CoversClass(WhereThrough::class)]
final class ThroughPathResolverTest extends TestCase
{
    #[Test]
    public function theBuilderDeclaresADelimitedListByDefault(): void
    {
        $filter = WhereThroughIn::make('advertiser', 'campaign.advertiser')->build();

        self::assertSame('advertiser', $filter->key());
        self::assertSame('campaign.advertiser', $filter->throughPath());
        self::assertSame(',', $filter->listValue()->delimiter);
        self::assertNull($filter->resolvedPath());
        self::assertSame([], $filter->constraints());
    }

    #[Test]
    public function aSingleArgumentUsesThePathAsTheKey(): void
    {
        $filter = WhereThroughIn::make('templates')->build();

        self::assertSame('templates', $filter->key());
        self::assertSame('templates', $filter->path);
    }

    #[Test]
    public function theBuilderCarriesTheValueMetadata(): void
    {
        $filter = WhereThroughIn::make('advertiser', 'campaign.advertiser')
            ->acceptsList(delimited: false)
            ->integer()
            ->describedAs('Campaigns of these advertisers.')
            ->example(['4', '7'])
            ->deprecated()
            ->build();

        self::assertFalse($filter->listValue()->isDelimited());
        self::assertEquals([new Pattern('^-?[0-9]+$', documentsAs: 'integer')], $filter->constraints());
        self::assertSame('Campaigns of these advertisers.', $filter->getDescription());
        self::assertTrue($filter->hasExample());
        self::assertSame(['4', '7'], $filter->getExample());
        self::assertTrue($filter->isDeprecated());
    }

    #[Test]
    public function aPathEndingOnAFieldStaysAFieldLeaf(): void
    {
        $path = $this->resolver()->path('name', 'campaign.name', $this->leads());

        self::assertFalse($path->endsOnRelation());
        self::assertSame(['campaign', 'name'], $path->segments);
        self::assertNull($path->relatedType);
        self::assertSame([], $path->idConstraints());
        self::assertSame('x', $path->storageValue('name', 'x'));
    }

    #[Test]
    public function aPathEndingOnAToOneRelationComparesTheRelatedId(): void
    {
        $path = $this->resolver()->path('advertiser', 'campaign.advertiser', $this->leads());

        self::assertTrue($path->endsOnRelation());
        self::assertSame(['campaign', 'advertiser'], $path->segments);
        self::assertSame('advertisers', $path->relatedType);
        self::assertSame('advertiser', $path->leafRelation->name());
        self::assertSame('id', $path->relatedIdColumn());
        self::assertEquals([new Pattern('^[0-9]+$', documentsAs: 'integer')], $path->idConstraints());
    }

    #[Test]
    public function aBareToManyRelationIsALeafOnTheOwner(): void
    {
        $path = $this->resolver()->path('template', 'templates', $this->leads());

        self::assertTrue($path->endsOnRelation());
        self::assertSame(['templates'], $path->segments);
        self::assertSame('templates', $path->relatedType);
        self::assertEquals([new UuidFormat()], $path->idConstraints());
    }

    #[Test]
    public function aSegmentMatchesARelationByItsStorageName(): void
    {
        $resolver = $this->resolver();

        self::assertSame('advertisers', $resolver->path('owner', 'campaign.ownerEntity', $this->leads())->relatedType);
        // The member name is not the storage name, so the path reads as a field as before.
        self::assertFalse($resolver->path('owner', 'campaign.owner', $this->leads())->endsOnRelation());
    }

    #[Test]
    public function anEncodedRelatedIdIsDecodedToItsStorageKey(): void
    {
        $filter = $this->resolver()->resolve(WhereThroughIn::make('brand')->build(), $this->leads());
        self::assertInstanceOf(WhereThroughIn::class, $filter);

        self::assertSame(['321', '54'], $filter->storageValues('123,45'));
        self::assertSame(['321'], $filter->storageValues(['123']));
        self::assertSame([], $filter->storageValues([]));
    }

    #[Test]
    public function anUndecodableIdIsRejectedAtTheParameter(): void
    {
        $filter = $this->resolver()->resolve(WhereThroughIn::make('brand')->build(), $this->leads());
        self::assertInstanceOf(WhereThroughIn::class, $filter);

        try {
            $filter->storageValues('123,');
            self::fail('An undecodable id must be rejected.');
        } catch (FilterValueInvalid $e) {
            self::assertSame('brand', $e->filterKey);
            self::assertSame(400, $e->getStatusCode());
        }
    }

    #[Test]
    public function aDeclaredConstraintReplacesTheRelatedIdFormat(): void
    {
        $filter = $this->resolver()->resolve(WhereThroughIn::make('template', 'templates')->pattern('^[a-f0-9-]+$')->build(), $this->leads());

        self::assertEquals([new Pattern('^[a-f0-9-]+$')], $filter->constraints());
    }

    #[Test]
    public function aFieldLeafKeepsItsMembersAsRequested(): void
    {
        $filter = $this->resolver()->resolve(WhereThroughIn::make('name', 'campaign.name')->build(), $this->leads());
        self::assertInstanceOf(WhereThroughIn::class, $filter);

        self::assertSame(['a', 'b'], $filter->storageValues('a, b'));
        self::assertSame([], $filter->constraints());
    }

    #[Test]
    public function aPolymorphicToOneLeafIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('polymorphic relation "subject"');

        $this->resolver()->resolve(WhereThroughIn::make('subject')->build(), $this->leads());
    }

    #[Test]
    public function aPolymorphicToManyLeafIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('polymorphic relation "mentions"');

        $this->resolver()->resolve(WhereThrough::make('mentions')->build(), $this->leads());
    }

    #[Test]
    public function aLeafOnAnUnregisteredTypeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"ghosts" is not a registered resource');

        $this->resolver()->resolve(WhereThroughIn::make('ghost')->build(), $this->leads());
    }

    #[Test]
    public function aWhereThroughOnARelationCannotTransformItsValue(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('drop deserializeUsing()');

        $this->resolver()->resolve(
            WhereThrough::make('advertiser', 'campaign.advertiser')->deserializeUsing(static fn(mixed $v): mixed => $v)->build(),
            $this->leads(),
        );
    }

    #[Test]
    public function aWhereThroughOnARelationComparesOnlyByEquality(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot use the "like" operator');

        $this->resolver()->resolve(WhereThrough::make('advertiser', 'campaign.advertiser')->operator('like')->build(), $this->leads());
    }

    #[Test]
    public function aWhereThroughOnARelationDecodesItsValue(): void
    {
        $filter = $this->resolver()->resolve(WhereThrough::make('brand')->operator('!=')->build(), $this->leads());
        self::assertInstanceOf(WhereThrough::class, $filter);

        self::assertSame('321', $filter->storageValue('123'));
        self::assertSame([], $filter->constraints());
    }

    #[Test]
    public function aGroupsChildrenAreResolvedAndOtherFiltersPassThrough(): void
    {
        $plain = Where::make('name')->build();
        $group = WhereAny::make('q', WhereThroughIn::make('advertiser', 'campaign.advertiser'), $plain)->build();

        $resolved = $this->resolver()->resolveAll([$group, $plain], $this->leads());

        self::assertSame($plain, $resolved[1]);
        self::assertInstanceOf(WhereAny::class, $resolved[0]);
        $child = $resolved[0]->children[0];
        self::assertInstanceOf(WhereThroughIn::class, $child);
        self::assertTrue($child->resolvedPath()?->endsOnRelation());
        self::assertSame($plain, $resolved[0]->children[1]);
    }

    #[Test]
    public function resolvingIsIdempotent(): void
    {
        $resolver = $this->resolver();
        $once = $resolver->resolve(WhereThroughIn::make('template', 'templates')->build(), $this->leads());

        self::assertSame($once, $resolver->resolve($once, $this->leads()));
    }

    #[Test]
    public function theRegisteredResourcesProvideTheGraph(): void
    {
        $registry = new StubSerializerResolver('none');
        foreach (self::types() as $type => $fields) {
            $registry->withSerializer($type, self::resource($type, $fields));
        }

        $path = ThroughPathResolver::forResolver($registry)->path('advertiser', 'campaign.advertiser', $this->leads());

        self::assertSame('advertisers', $path->relatedType);
        self::assertEquals([new Pattern('^[0-9]+$', documentsAs: 'integer')], $path->idConstraints());
    }

    #[Test]
    public function aListValueIsAlwaysDeclared(): void
    {
        self::assertEquals(ListValue::delimited(), WhereThroughIn::make('x')->build()->listValue());
    }

    private function resolver(): ThroughPathResolver
    {
        $types = self::types();

        return new ThroughPathResolver(static fn(string $type): ?array => $types[$type] ?? null);
    }

    /**
     * @return list<FieldInterface>
     */
    private function leads(): array
    {
        return self::types()['leads'];
    }

    /**
     * @return array<string, list<FieldInterface>>
     */
    private static function types(): array
    {
        return [
            'leads' => [
                Id::make()->numeric()->build(),
                Str::make('name')->build(),
                BelongsTo::make('campaign', 'campaigns')->build(),
                HasMany::make('templates', 'templates')->build(),
                BelongsTo::make('brand', 'brands')->build(),
                BelongsTo::make('ghost', 'ghosts')->build(),
                MorphTo::make('subject', ['campaigns', 'templates'])->build(),
                MorphToMany::make('mentions', ['campaigns'])->build(),
            ],
            'campaigns' => [
                Id::make()->numeric()->build(),
                Str::make('name')->build(),
                BelongsTo::make('advertiser', 'advertisers')->build(),
                BelongsTo::make('owner', 'advertisers')->storedAs('ownerEntity')->build(),
            ],
            'advertisers' => [Id::make()->numeric()->build()],
            'templates' => [Id::make()->uuid()->build()],
            'brands' => [Id::make()->encodeUsing(new ReversingIdEncoder())->build()],
        ];
    }

    /**
     * @param list<FieldInterface> $fields
     */
    private static function resource(string $type, array $fields): AbstractResource
    {
        $resource = new class extends AbstractResource {
            /**
             * @var list<FieldInterface>
             */
            public array $declared = [];

            public function fields(): array
            {
                return $this->declared;
            }
        };
        $resource->declared = $fields;

        return $resource;
    }
}
