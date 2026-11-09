<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractRelationBuilder::class)]
#[CoversClass(AbstractRelationValue::class)]
final class IdentifierMetaFieldsTest extends TestCase
{
    #[Test]
    public function aRelationCarriesTheMembersItsIdentifierMetaDeclares(): void
    {
        $relation = HasMany::make('members', 'users')
            ->identifierMeta(static fn(): array => [], fields: [
                Str::make('role'),
                Boolean::make('primary')->build(),
            ])
            ->build();

        self::assertSame(['role', 'primary'], \array_map(static fn($field): string => $field->name(), $relation->identifierMetaFields()));
        self::assertInstanceOf(Str::class, $relation->identifierMetaFields()[0]);
    }

    #[Test]
    public function aRelationWithoutDeclaredMembersCarriesNone(): void
    {
        self::assertSame([], BelongsTo::make('author', 'users')->identifierMeta(static fn(): array => [])->build()->identifierMetaFields());
        self::assertSame([], BelongsTo::make('author', 'users')->build()->identifierMetaFields());
    }

    #[Test]
    public function polymorphicRelationsCarryTheirDeclaredMembers(): void
    {
        $toOne = MorphTo::make('subject', ['users', 'teams'])->identifierMeta(static fn(): array => [], fields: [Str::make('role')])->build();
        $toMany = MorphToMany::make('subjects', ['users', 'teams'])->identifierMeta(static fn(): array => [], fields: [Str::make('role')])->build();

        self::assertCount(1, $toOne->identifierMetaFields());
        self::assertCount(1, $toMany->identifierMetaFields());
    }

    #[Test]
    public function aMemberNamedPivotIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"pivot"');

        BelongsToMany::make('tags', 'tags')->identifierMeta(static fn(): array => [], fields: [Str::make('pivot')]);
    }
}
