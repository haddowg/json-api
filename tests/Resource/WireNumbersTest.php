<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\WireNumbers;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A whole number written where an integer is declared becomes an `int`, and an integer
 * written where a number is declared a `float`, at every position a field declares,
 * before anything validates the write.
 */
#[CoversClass(WireNumbers::class)]
final class WireNumbersTest extends TestCase
{
    #[Test]
    public function everyDeclaredPositionTakesItsFieldsNumberType(): void
    {
        $data = WireNumbers::resource((new WireNumbersTrackResource())->allFields(), [
            'type' => 'tracks',
            'attributes' => [
                'plays' => 5.0,
                'rating' => 4,
                'title' => 7.0,
                'stats' => ['skips' => 2.0, 'extra' => 3.0, 'avg' => 1],
                'totals' => ['count' => 6.0],
                'shape' => ['kind' => 'square', 'side' => 8.0],
                'counts' => [1.0, 2, 2.5],
                'weights' => [1, 2.5],
                'size' => 9.0,
                'rows' => [['n' => 10.0]],
                'unknown' => 11.0,
            ],
            'relationships' => [
                'tags' => ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 12.0, 'weight' => 13]]]]],
            ],
        ]);

        self::assertSame([
            'plays' => 5,
            'rating' => 4.0,
            'title' => 7.0,
            'stats' => ['skips' => 2, 'extra' => 3, 'avg' => 1.0],
            'totals' => ['count' => 6],
            'shape' => ['kind' => 'square', 'side' => 8],
            'counts' => [1, 2, 2.5],
            'weights' => [1.0, 2.5],
            'size' => 9,
            'rows' => [['n' => 10]],
            'unknown' => 11.0,
        ], $data['attributes']);
        self::assertSame(
            ['tags' => ['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 12, 'weight' => 13.0]]]]]],
            $data['relationships'],
        );
    }

    #[Test]
    public function aWrongTypeIsLeftForValidation(): void
    {
        $data = WireNumbers::resource((new WireNumbersTrackResource())->allFields(), [
            'attributes' => ['plays' => 5.5, 'rating' => '4', 'stats' => 'x', 'counts' => 'y'],
        ]);

        self::assertSame(['plays' => 5.5, 'rating' => '4', 'stats' => 'x', 'counts' => 'y'], $data['attributes']);
    }

    #[Test]
    public function anOperationsBodyIsNormalizedAgainstItsTargetType(): void
    {
        $server = (new Server())->register(WireNumbersTrackResource::class);

        $create = WireNumbers::operation(new CreateResourceOperation(
            new Target('tracks'),
            new QueryParameters([], [], [], [], []),
            new OperationContext($server),
            $this->request(['data' => ['type' => 'tracks', 'attributes' => ['plays' => 5.0]]]),
        ), $server);
        self::assertInstanceOf(CreateResourceOperation::class, $create);
        self::assertSame(['plays' => 5], $create->body()->getResourceAttributes());

        $linkage = WireNumbers::operation(new UpdateRelationshipOperation(
            new Target('tracks', '1', 'tags', true),
            new QueryParameters([], [], [], [], []),
            new OperationContext($server),
            $this->request(['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 3.0]]]]]),
        ), $server);
        self::assertInstanceOf(UpdateRelationshipOperation::class, $linkage);
        $body = $linkage->body()->getParsedBody();
        self::assertIsArray($body);
        self::assertSame(['data' => [['type' => 'tags', 'id' => '1', 'meta' => ['pivot' => ['rank' => 3]]]]], $body);
    }

    #[Test]
    public function anUnchangedBodyKeepsItsRequest(): void
    {
        $request = $this->request(['data' => ['type' => 'tracks', 'attributes' => ['plays' => 5]]]);

        self::assertSame($request, WireNumbers::resourceDocument(new WireNumbersTrackResource(), $request));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): JsonApiRequest
    {
        return new JsonApiRequest((new ServerRequest('POST', '/tracks'))->withParsedBody($body));
    }
}

final class WireNumbersTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Integer::make('plays'),
            Decimal::make('rating'),
            Str::make('title'),
            Obj::make('stats')->fields(Integer::make('skips'), Decimal::make('avg'))->additionalProperties(Integer::make('*')),
            Map::make('totals')->fields(Integer::make('count')),
            OneOf::make('shape')->discriminator('kind')->variant('square', Integer::make('side')),
            ArrayList::make('counts')->of('integer'),
            ArrayList::make('weights')->of('number'),
            Union::make('size')->of(Str::make(), Integer::make()),
            ArrayList::make('rows')->of(Obj::make('row')->fields(Integer::make('n'))),
            BelongsToMany::make('tags', 'tags')->fields(Integer::make('rank'), Decimal::make('weight')),
        ];
    }
}
