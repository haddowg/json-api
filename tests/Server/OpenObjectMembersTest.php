<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AttributeMemberUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ObjectValue;
use haddowg\JsonApi\Resource\ObjectValues;
use haddowg\JsonApi\Resource\UndeclaredAttributeMembers;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An open object declares every member its member field takes, so hydration accepts
 * them, and {@see ObjectValues} finds each open or bounded object for an adapter to
 * validate.
 */
#[CoversClass(UndeclaredAttributeMembers::class)]
#[CoversClass(ObjectValues::class)]
#[CoversClass(ObjectValue::class)]
final class OpenObjectMembersTest extends TestCase
{
    #[Test]
    public function anOpenObjectsMembersAreDeclaredAndHydrated(): void
    {
        $model = $this->hydrate('POST', [
            'rows' => [['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42']],
            'labels' => ['a/b' => 'x'],
        ]);

        self::assertSame([['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42']], $model->rows);
        self::assertSame(['a/b' => 'x'], $model->labels);
    }

    #[Test]
    public function aClosedObjectInsideAMemberStillRefusesUndeclaredKeys(): void
    {
        try {
            $this->hydrate('PATCH', [
                'nested' => ['first' => ['n' => 1, 'stray' => 2]],
                'meta' => ['a' => 'x', 'b' => 'y'],
            ]);
            self::fail('Expected AttributeMemberUnrecognized');
        } catch (AttributeMemberUnrecognized $e) {
            self::assertSame(['/data/attributes/nested/first/stray', '/data/attributes/meta/b'], $e->pointers);
        }
    }

    #[Test]
    public function aHiddenMemberFieldLeavesTheObjectClosed(): void
    {
        $field = Obj::make('meta')->additionalProperties(Str::make('*')->hidden())->build();

        self::assertSame(['/data/attributes/meta/x'], UndeclaredAttributeMembers::in([$field], ['meta' => ['x' => 'y']]));
    }

    #[Test]
    public function aReadOnlyMemberFieldsMembersAreIgnoredNotRefused(): void
    {
        $field = Obj::make('metrics')->additionalProperties(Integer::make('*')->readOnly())->build();

        self::assertSame([], UndeclaredAttributeMembers::in([$field], ['metrics' => ['views' => 3]]));
    }

    #[Test]
    public function everyOpenOrBoundedObjectIsFoundWithItsPointer(): void
    {
        $found = ObjectValues::in((new OpenWidget())->allFields(), [
            'title' => 'x',
            'rows' => [['Key' => 'a', 'Vendor' => 'b'], ['Key' => 'c', 'x~y/z' => 'd'], 'not an object'],
            'meta' => ['a' => 'x'],
            'nested' => ['first' => ['n' => 1]],
            'block' => ['kind' => 'tagged', 'labels' => ['k' => 'v']],
        ], true, new StubJsonApiRequest());

        self::assertSame(
            ['/data/attributes/rows/0', '/data/attributes/rows/1', '/data/attributes/meta', '/data/attributes/nested', '/data/attributes/block/labels'],
            \array_map(static fn(ObjectValue $value): string => $value->pointer, $found),
        );
        self::assertSame(['Vendor' => 'b'], $found[0]->additional);
        self::assertSame('/data/attributes/rows/1/x~0y~1z', $found[1]->memberPointer('x~y/z'));
        self::assertEquals([new MaxProperties(3)], $found[0]->memberCounts(true));
        // A closed object bounding its members is found, with nothing to validate beyond its count.
        self::assertSame([], $found[2]->additional);
        self::assertNull($found[2]->memberField);
        self::assertEquals([new MinProperties(1, new Context(onCreate: false))], $found[2]->memberCounts(false));
        self::assertSame([], $found[2]->memberCounts(true));
        self::assertSame(['first' => ['n' => 1]], $found[3]->additional);
    }

    #[Test]
    public function aReadOnlyMemberFieldGivesNoMembersToValidate(): void
    {
        $found = ObjectValues::in([Obj::make('metrics')->additionalProperties(Integer::make('*')->readOnly())->build()], ['metrics' => ['views' => 'x']], true, new StubJsonApiRequest());

        self::assertCount(1, $found);
        self::assertSame([], $found[0]->additional);
        self::assertNull($found[0]->memberField);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function hydrate(string $method, array $attributes): \stdClass
    {
        $data = ['type' => 'open-widgets', 'attributes' => $attributes];
        if ($method === 'PATCH') {
            $data['id'] = '1';
        }
        $request = new JsonApiRequest(
            (new ServerRequest($method, '/open-widgets', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody(['data' => $data]),
        );
        $model = (object) ['id' => '1', 'title' => null, 'rows' => [], 'labels' => [], 'meta' => [], 'nested' => [], 'block' => null];
        $hydrated = Server::make()->register(OpenWidget::class)->resourceFor('open-widgets')->hydrate($request, $model);
        \assert($hydrated instanceof \stdClass);

        return $hydrated;
    }
}

final class OpenWidget extends AbstractResource
{
    public static string $type = 'open-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            ArrayList::make('rows')->of(Obj::make('row')->fields(Str::make('Key'))->additionalProperties(Str::make('*')->maxLength(200))->maxProperties(3)),
            Obj::make('labels')->additionalProperties(Str::make('*')),
            Obj::make('meta')->fields(Str::make('a'))->onUpdate(static function ($f): void {
                $f->minProperties(1);
            }),
            Obj::make('nested')->additionalProperties(Obj::make('*')->fields(Integer::make('n'))),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('tagged', Obj::make('labels')->additionalProperties(Str::make('*'))),
        ];
    }
}
