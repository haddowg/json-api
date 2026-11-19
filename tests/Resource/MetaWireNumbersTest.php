<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\WireNumbers;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetaShape::class)]
#[CoversClass(WireNumbers::class)]
final class MetaWireNumbersTest extends TestCase
{
    #[Test]
    public function aDeclaredMetaObjectPutsEachNumberInItsFieldsType(): void
    {
        $shape = MetaShape::of([Integer::make('count'), Decimal::make('ratio'), Str::make('label')]);

        self::assertSame(['count' => 5, 'ratio' => 2.0, 'label' => 'x', 'other' => 3.0], $shape->normalize(['count' => 5.0, 'ratio' => 2, 'label' => 'x', 'other' => 3.0]));
        self::assertSame(['count' => 5], $shape->values(['count' => 5.0], true));
    }

    #[Test]
    public function anEntrysExtractHookTakesTheFourArgumentsEveryExtractHookTakes(): void
    {
        $entry = AdditionalProperties::make()
            ->extractUsing(static fn(array $row, \haddowg\JsonApi\Request\JsonApiRequestInterface $request, string $name, ?\haddowg\JsonApi\Resource\Field\KeySelection $keys): array => ['keys' => $keys === null ? 'none' : 'some', 'name' => $name])
            ->build();

        self::assertSame(['keys' => 'none', 'name' => '*'], $entry->extract([], new JsonApiRequest(new ServerRequest('GET', '/notes'))));
    }

    #[Test]
    public function anOpenObjectsMembersFollowTheEntrysValueField(): void
    {
        $shape = MetaShape::of([AdditionalProperties::make()->of(Integer::make('*'))]);

        self::assertSame(['a' => 1, 'b' => 'x'], $shape->normalize(['a' => 1.0, 'b' => 'x']));
    }

    #[Test]
    public function aWritesTopLevelAndResourceMetaAreNormalized(): void
    {
        $request = new JsonApiRequest((new ServerRequest('POST', '/notes'))->withParsedBody([
            'data' => ['type' => 'notes', 'meta' => ['revision' => 4.0]],
            'meta' => ['attempt' => 2.0, 'free' => 1.0],
        ]));

        $normalized = WireNumbers::meta($request, MetaShape::of([Integer::make('attempt')]), MetaShape::of([Integer::make('revision')]));
        $body = $normalized->getParsedBody();

        self::assertIsArray($body);
        self::assertSame(['attempt' => 2, 'free' => 1.0], $body['meta']);
        self::assertSame(['type' => 'notes', 'meta' => ['revision' => 4]], $body['data']);
        self::assertSame($request, WireNumbers::meta($request, MetaShape::none()));
    }

    #[Test]
    public function anOpenPivotsUndeclaredMembersAreNormalizedToo(): void
    {
        $relation = BelongsToMany::make('rooms', 'rooms')->fields(Integer::make('nights'), AdditionalProperties::make()->of(Integer::make('*')))->build();

        self::assertSame(
            [['type' => 'rooms', 'id' => '1', 'meta' => ['pivot' => ['nights' => 2, 'floor' => 3]]]],
            WireNumbers::linkage($relation, [['type' => 'rooms', 'id' => '1', 'meta' => ['pivot' => ['nights' => 2.0, 'floor' => 3.0]]]]),
        );
    }
}
