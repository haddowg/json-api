<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\StorageCastInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A server's storage cast puts each attribute value core assigns to a model in the form
 * the data layer stores, here a decimal string in a property typed `string`.
 */
#[CoversClass(Server::class)]
#[CoversClass(AbstractResource::class)]
final class StorageCastTest extends TestCase
{
    #[Test]
    public function theCastRunsBeforeTheAssignment(): void
    {
        $item = new ScItem();

        $this->resource(new ScDecimalCast())->hydrate($this->patch(['price' => 19.99, 'name' => 'Lamp']), $item);

        self::assertSame('19.99', $item->price);
        self::assertSame('Lamp', $item->name);
    }

    #[Test]
    public function theCastIsAskedWithTheModelMemberValueAndField(): void
    {
        $cast = new ScDecimalCast();
        $item = new ScItem();

        $this->resource($cast)->hydrate($this->patch(['price' => 20]), $item);

        self::assertSame([[$item, 'price', 20.0, 'price']], $cast->calls);
        self::assertSame('20.00', $item->price);
    }

    #[Test]
    public function aFlattenedAttributeIsCastOntoItsRelatedModel(): void
    {
        $item = new ScItem();
        $item->owner = new ScOwner();

        $this->resource(new ScDecimalCast())->hydrate($this->patch(['ownerBalance' => 1.5]), $item);

        self::assertSame('1.50', $item->owner->balance);
    }

    #[Test]
    public function aFillUsingHookAndAnOverriddenHydrateWriteWithoutTheCast(): void
    {
        $cast = new ScDecimalCast();
        $item = new ScItem();

        $this->resource($cast)->hydrate($this->patch(['filled' => 2.5, 'dimensions' => ['width' => 3.5]]), $item);

        self::assertSame([], $cast->calls);
        self::assertSame(2.5, $item->filled);
        self::assertSame(['width' => 3.5], $item->dimensions);
    }

    #[Test]
    public function withoutACastAFloatIsAssignedAsHydrated(): void
    {
        $item = new ScFloatItem();

        Server::make()->register(ScFloatItemResource::class)->resourceFor('sc-float-items')
            ->hydrate($this->patch(['price' => 19.99], 'sc-float-items'), $item);

        self::assertSame(19.99, $item->price);
    }

    #[Test]
    public function theServerAndItsResourcesCarryTheCast(): void
    {
        $cast = new ScDecimalCast();
        $server = Server::make()->withStorageCast($cast);

        self::assertSame($cast, $server->storageCast());
        self::assertNull(Server::make()->storageCast());
    }

    private function resource(StorageCastInterface $cast): AbstractResource
    {
        return Server::make()
            ->withStorageCast($cast)
            ->register(ScItemResource::class)
            ->register(ScOwnerResource::class)
            ->resourceFor('sc-items');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function patch(array $attributes, string $type = 'sc-items'): JsonApiRequest
    {
        return new JsonApiRequest(
            (new ServerRequest('PATCH', '/' . $type . '/1', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody(['data' => ['type' => $type, 'id' => '1', 'attributes' => $attributes]]),
        );
    }
}

final class ScDecimalCast implements StorageCastInterface
{
    /** @var list<array{object, string, mixed, string}> */
    public array $calls = [];

    public function forStorage(object $model, string $member, mixed $value, FieldInterface $field): mixed
    {
        $this->calls[] = [$model, $member, $value, $field->name()];

        return $field instanceof Decimal && \is_float($value) ? \sprintf('%.2F', $value) : $value;
    }
}

final class ScItem
{
    public string $price = '0.00';

    public string $name = '';

    public ?ScOwner $owner = null;

    public ?float $filled = null;

    /** @var array<string, mixed> */
    public array $dimensions = [];
}

final class ScOwner
{
    public string $balance = '0.00';
}

final class ScFloatItem
{
    public ?float $price = null;
}

final class ScItemResource extends AbstractResource
{
    public static string $type = 'sc-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('price'),
            Str::make('name'),
            Decimal::make('ownerBalance')->on('owner')->storedAs('balance'),
            Decimal::make('filled')->fillUsing(static function (mixed $model, mixed $value): mixed {
                \assert($model instanceof ScItem && \is_float($value));
                $model->filled = $value;

                return $model;
            }),
            Obj::make('dimensions')->fields(Decimal::make('width')),
            BelongsTo::make('owner', 'sc-owners'),
        ];
    }
}

final class ScOwnerResource extends AbstractResource
{
    public static string $type = 'sc-owners';

    public function fields(): array
    {
        return [Id::make()];
    }
}

final class ScFloatItemResource extends AbstractResource
{
    public static string $type = 'sc-float-items';

    public function fields(): array
    {
        return [Id::make(), Decimal::make('price')];
    }
}
