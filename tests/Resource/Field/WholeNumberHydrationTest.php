<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * JSON may write a whole number as `5.0`, which the type check accepts for an integer,
 * and `5` for a number. Hydration and every write hook receive an integer's value as an
 * `int` and a number's as a `float`, at any depth.
 */
#[CoversClass(AbstractFieldValue::class)]
#[CoversClass(Integer::class)]
#[CoversClass(Decimal::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(Obj::class)]
#[CoversClass(Map::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(Union::class)]
final class WholeNumberHydrationTest extends TestCase
{
    #[Test]
    public function anIntegerStoresAWholeFloatAsAnInt(): void
    {
        self::assertSame(['plays' => 5], $this->hydrate(Integer::make('plays'), 5.0));
    }

    #[Test]
    public function anIntegerHandsItsHooksAnInt(): void
    {
        $seen = [];
        $this->hydrate(Integer::make('plays')->fillUsing(static function (mixed $model, mixed $value) use (&$seen): mixed {
            $seen[] = $value;

            return $model;
        }), 5.0);
        $this->hydrate(Integer::make('plays')->deserializeUsing(static function (mixed $value) use (&$seen): mixed {
            $seen[] = $value;

            return $value;
        }), 7.0);

        self::assertSame([5, 7], $seen);
    }

    #[Test]
    public function aDecimalHandsItsHooksAFloat(): void
    {
        $seen = null;
        $this->hydrate(Decimal::make('rating')->fillUsing(static function (mixed $model, mixed $value) use (&$seen): mixed {
            $seen = $value;

            return $model;
        }), 5);

        self::assertSame(5.0, $seen);
    }

    #[Test]
    public function anIntegerListStoresAndHandsOverInts(): void
    {
        self::assertSame(['counts' => [1, 2]], $this->hydrate(ArrayList::make('counts')->of('integer'), [1.0, 2]));
        self::assertSame(['weights' => [1.0, 2.0]], $this->hydrate(ArrayList::make('weights')->of('number'), [1.0, 2]));

        $seen = null;
        $this->hydrate(ArrayList::make('counts')->of('integer')->fillUsing(static function (mixed $model, mixed $value) use (&$seen): mixed {
            $seen = $value;

            return $model;
        }), [3.0]);

        self::assertSame([3], $seen);
    }

    #[Test]
    public function aNestedIntegerChildReceivesAnInt(): void
    {
        $seen = null;
        $obj = Obj::make('stats')->fields(Integer::make('plays')->fillUsing(static function (mixed $model, mixed $value) use (&$seen): mixed {
            $seen = $value;

            return $model;
        }));
        $this->hydrate($obj, ['plays' => 4.0]);

        self::assertSame(4, $seen);
        self::assertSame(['stats' => ['plays' => 4]], $this->hydrate(Obj::make('stats')->fields(Integer::make('plays')), ['plays' => 4.0]));
    }

    #[Test]
    public function aHookedCompositeReceivesItsIntegerMembersAsInts(): void
    {
        $seen = [];
        $record = static function (mixed $model, mixed $value) use (&$seen): mixed {
            $seen[] = $value;

            return $model;
        };

        $this->hydrate(Obj::make('stats')->fields(Integer::make('plays'))->additionalProperties(Integer::make('*'))->fillUsing($record), ['plays' => 1.0, 'skips' => 2.0]);
        $this->hydrate(Map::make('stats')->fields(Integer::make('plays'))->fillUsing($record), ['plays' => 3.0]);
        $this->hydrate(OneOf::make('shape')->discriminator('kind')->variant('square', Integer::make('side'))->fillUsing($record), ['kind' => 'square', 'side' => 4.0]);
        $this->hydrate(ArrayList::make('rows')->of(Obj::make('row')->fields(Integer::make('n')))->fillUsing($record), [['n' => 5.0]]);
        $this->hydrate(Union::make('size')->of(Str::make(), Integer::make())->fillUsing($record), 6.0);

        self::assertSame([
            ['plays' => 1, 'skips' => 2],
            ['plays' => 3],
            ['kind' => 'square', 'side' => 4],
            [['n' => 5]],
            6,
        ], $seen);
    }

    /**
     * @param AbstractFieldBuilder<mixed> $builder
     *
     * @return array<array-key, mixed>
     */
    private function hydrate(AbstractFieldBuilder $builder, mixed $value): array
    {
        $model = $builder->build()->hydrate([], $value, [], new StubJsonApiRequest(), true);
        self::assertIsArray($model);

        return $model;
    }
}
