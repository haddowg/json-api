<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Relationship\RelationshipLinkage;
use haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A polymorphic to-many renders the members a host supplies through the out-of-band
 * linkage seam, in its linkage and in `included`, and reads the model only when the
 * host supplies none.
 */
#[CoversClass(MorphToMany::class)]
final class MorphToManyLinkageTest extends TestCase
{
    #[Test]
    public function suppliedMembersReplaceTheModelsInLinkageAndIncluded(): void
    {
        $body = $this->fetch(new MlSuppliedLinkage());

        self::assertSame(
            [['type' => 'ml-people', 'id' => '2'], ['type' => 'ml-places', 'id' => '7']],
            self::dig($body, 'data', 'relationships', 'contacts', 'data'),
        );
        $included = self::dig($body, 'included');
        self::assertIsArray($included);
        self::assertSame(['2', '7'], \array_map(static fn(mixed $resource): mixed => self::dig($resource, 'id'), $included));
    }

    #[Test]
    public function withoutASuppliedLinkageTheModelsMembersRender(): void
    {
        $body = $this->fetch(null);

        self::assertSame(
            [['type' => 'ml-people', 'id' => '1'], ['type' => 'ml-people', 'id' => '2'], ['type' => 'ml-places', 'id' => '7']],
            self::dig($body, 'data', 'relationships', 'contacts', 'data'),
        );
    }

    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(?RelationshipLinkageInterface $linkage): array
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withPsr17($psr17, $psr17)
            ->withRelationshipLinkage($linkage)
            ->register(MlBook::class)
            ->register(MlPerson::class)
            ->register(MlPlace::class)
            ->withHandler(new MlHandler());

        $request = (new ServerRequest('GET', '/ml-books/1?include=contacts', ['Accept' => 'application/vnd.api+json']))
            ->withQueryParams(['include' => 'contacts'])
            ->withAttribute(Target::class, new Target('ml-books', '1'));

        $response = $server->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> */
        return \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class MlStore
{
    /** @var array<string, object>|null */
    private static ?array $rows = null;

    /**
     * @return array<string, object>
     */
    public static function rows(): array
    {
        if (self::$rows === null) {
            $hidden = new MlPersonRow('1', 'Hidden');
            $shown = new MlPersonRow('2', 'Shown');
            $place = new MlPlaceRow('7', 'Square');
            self::$rows = [
                'book' => (object) ['id' => '1', 'title' => 'Atlas', 'contacts' => [$hidden, $shown, $place]],
                'shown' => $shown,
                'place' => $place,
            ];
        }

        return self::$rows;
    }
}

final class MlPersonRow
{
    public function __construct(public string $id, public string $name) {}
}

final class MlPlaceRow
{
    public function __construct(public string $id, public string $name) {}
}

final class MlSuppliedLinkage implements RelationshipLinkageInterface
{
    public function linkageForRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?RelationshipLinkage
    {
        $rows = MlStore::rows();

        return $relation->name() === 'contacts' ? new RelationshipLinkage([$rows['shown'], $rows['place']]) : null;
    }
}

final readonly class MlHandler implements OperationHandlerInterface
{
    public function handle(JsonApiOperationInterface $operation): DataResponse
    {
        return DataResponse::fromResource(MlStore::rows()['book'], $operation->context()->server->serializerFor('ml-books'));
    }
}

final class MlBook extends AbstractResource
{
    public static string $type = 'ml-books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphToMany::make('contacts', ['ml-people', 'ml-places']),
        ];
    }
}

final class MlPerson extends AbstractResource
{
    public static string $type = 'ml-people';

    public function getType(mixed $object): string
    {
        return $object instanceof MlPersonRow ? self::$type : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}

final class MlPlace extends AbstractResource
{
    public static string $type = 'ml-places';

    public function getType(mixed $object): string
    {
        return $object instanceof MlPlaceRow ? self::$type : 'unknown';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
