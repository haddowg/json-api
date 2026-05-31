<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship as InputToMany;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship as InputToOne;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\RelationshipType;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Schema\Relationship\ToManyRelationship as OutputToMany;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship as OutputToOne;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\haddowg\JsonApi\Resource\Field\AbstractRelation::class)]
#[CoversClass(BelongsTo::class)]
#[CoversClass(HasOne::class)]
#[CoversClass(HasMany::class)]
#[CoversClass(BelongsToMany::class)]
#[CoversClass(MorphTo::class)]
final class RelationTest extends TestCase
{
    #[Test]
    public function belongsToIsToOneAndBuildsToOneRelationship(): void
    {
        $relation = BelongsTo::make('author')->type('users');
        $model = ['author' => ['id' => '7', 'type' => 'users']];

        self::assertFalse($relation->isToMany());
        self::assertSame(['users'], $relation->relatedTypes());

        try {
            $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        } catch (\Throwable $e) {
            \fwrite(\STDERR, "TR\n" . $e->getTraceAsString() . "\n");
            throw $e;
        }
        self::assertInstanceOf(OutputToOne::class, $built);
    }

    #[Test]
    public function hasManyIsToManyAndBuildsToManyRelationship(): void
    {
        $relation = HasMany::make('comments')->type('comments');
        $model = ['comments' => [['id' => '1'], ['id' => '2']]];

        self::assertTrue($relation->isToMany());

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        self::assertInstanceOf(OutputToMany::class, $built);
    }

    #[Test]
    public function hasOneInheritsBelongsToBehaviour(): void
    {
        $relation = HasOne::make('profile')->type('profiles');

        self::assertFalse($relation->isToMany());
        self::assertInstanceOf(OutputToOne::class, $relation->buildRelationship(['profile' => null], $this->request(), $this->resolver()));
    }

    #[Test]
    public function relatedTypeAppendsRelationshipTypeConstraint(): void
    {
        $relation = BelongsTo::make('author')->type('users')->required();
        $constraintTypes = \array_map(static fn(object $c): string => $c::class, $relation->constraints());

        self::assertContains(RelationshipType::class, $constraintTypes);
        self::assertContains(\haddowg\JsonApi\Resource\Constraint\Required::class, $constraintTypes);
    }

    #[Test]
    public function hasManyItemConstraints(): void
    {
        $relation = HasMany::make('tags')->type('tags')->maxItems(5);
        $constraintTypes = \array_map(static fn(object $c): string => $c::class, $relation->constraints());

        self::assertContains(MaxItems::class, $constraintTypes);
    }

    #[Test]
    public function cannotEagerLoadTogglesFlag(): void
    {
        self::assertTrue(HasMany::make('a')->canEagerLoad());
        self::assertFalse(HasMany::make('a')->cannotEagerLoad()->canEagerLoad());
    }

    #[Test]
    public function uriFieldNameDefaultsToNameAndCanBeOverridden(): void
    {
        $relation = BelongsTo::make('author');
        self::assertSame('author', $relation->uriFieldName());

        $relation->withUriFieldName('writer');
        self::assertSame('writer', $relation->uriFieldName());
    }

    #[Test]
    public function hydrateToOneStoresRelatedId(): void
    {
        $relation = BelongsTo::make('author')->type('users')->storedAs('author_id');
        $model = ['author_id' => null];

        $input = new InputToOne(new ResourceIdentifier('users', '99'));
        $result = $relation->hydrateRelationship($model, $input);

        self::assertIsArray($result);
        self::assertSame('99', $result['author_id']);
    }

    #[Test]
    public function hydrateToManyStoresRelatedIds(): void
    {
        $relation = HasMany::make('tags')->type('tags')->storedAs('tag_ids');
        $model = ['tag_ids' => []];

        $input = new InputToMany([
            new ResourceIdentifier('tags', '1'),
            new ResourceIdentifier('tags', '2'),
        ]);
        $result = $relation->hydrateRelationship($model, $input);

        self::assertIsArray($result);
        self::assertSame(['1', '2'], $result['tag_ids']);
    }

    #[Test]
    public function hydrateRelationshipRespectsFillUsing(): void
    {
        $relation = BelongsTo::make('author')->fillUsing(
            static function (mixed $model, mixed $rel): array {
                self::assertIsArray($model);
                self::assertInstanceOf(InputToOne::class, $rel);
                $model['filled'] = $rel->resourceIdentifier?->id;

                return $model;
            },
        );

        $result = $relation->hydrateRelationship(['filled' => null], new InputToOne(new ResourceIdentifier('users', '5')));
        self::assertIsArray($result);
        self::assertSame('5', $result['filled']);
    }

    #[Test]
    public function belongsToManyDeclaresPivotFields(): void
    {
        $relation = BelongsToMany::make('roles')->type('roles')->fields(['assigned_at' => 'datetime']);

        self::assertTrue($relation->isToMany());
        self::assertSame(['assigned_at' => 'datetime'], $relation->pivotFields());
    }

    #[Test]
    public function belongsToManyResolvesClosurePivotFields(): void
    {
        $relation = BelongsToMany::make('roles')->fields(static fn(): array => ['x' => 1]);

        self::assertSame(['x' => 1], $relation->pivotFields());
    }

    #[Test]
    public function morphToDeclaresMultipleTypes(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');

        self::assertFalse($relation->isToMany());
        self::assertSame(['posts', 'videos'], $relation->relatedTypes());
    }

    #[Test]
    public function morphToResolvesSerializerByRelatedType(): void
    {
        $relation = MorphTo::make('commentable')->types('posts', 'videos');
        $model = ['commentable' => ['kind' => 'videos']];

        $built = $relation->buildRelationship($model, $this->request(), $this->resolver());
        self::assertInstanceOf(OutputToOne::class, $built);
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }

    private function resolver(): SerializerResolver
    {
        return new StubSerializerResolver();
    }
}
