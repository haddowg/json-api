<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AttributeMemberUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\UndeclaredAttributeMembers;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A write body's attributes object, and every object a field declares inside it, is
 * closed: a member the resource does not declare is refused before anything hydrates.
 */
#[CoversClass(AbstractResource::class)]
#[CoversClass(UndeclaredAttributeMembers::class)]
#[CoversClass(AttributeMemberUnrecognized::class)]
final class UndeclaredAttributeMemberTest extends TestCase
{
    #[Test]
    public function anUndeclaredAttributeIsRefusedAndNothingIsWritten(): void
    {
        $model = self::model();

        try {
            $this->hydrate('PATCH', ['title' => 'New', 'colour' => 'red'], $model);
            self::fail('Expected AttributeMemberUnrecognized');
        } catch (AttributeMemberUnrecognized $e) {
            self::assertSame(['/data/attributes/colour'], $e->pointers);
            self::assertSame(400, $e->getStatusCode());
        }

        self::assertSame('Old', $model->title);
    }

    #[Test]
    public function undeclaredKeysInsideDeclaredObjectsAreEachNamed(): void
    {
        $this->expectRefused(
            [
                'meta' => ['a' => 'x', 'c' => 'y'],
                'block' => ['kind' => 'circle', 'radius' => '1', 'side' => '2'],
                'steps' => [['op' => 'trim'], ['op' => 'pad', 'width' => 3]],
            ],
            ['/data/attributes/meta/c', '/data/attributes/block/side', '/data/attributes/steps/1/width'],
        );
    }

    #[Test]
    public function aHiddenAttributeIsUndeclaredToTheClient(): void
    {
        $this->expectRefused(['secret' => 'x'], ['/data/attributes/secret']);
    }

    #[Test]
    public function aDeclaredReadOnlyMemberIsNotUndeclared(): void
    {
        $model = self::model();
        $this->hydrate('PATCH', ['views' => 99, 'meta' => ['a' => 'x', 'b' => 'ignored']], $model);

        self::assertSame(1, $model->views);
        self::assertSame(['a' => 'x'], $model->meta);
    }

    #[Test]
    public function anUnknownVariantIsNotLookedInside(): void
    {
        $model = self::model();
        $this->hydrate('PATCH', ['block' => ['kind' => 'hexagon', 'sides' => 6]], $model);

        self::assertSame(['kind' => 'hexagon', 'sides' => 6], $model->block);
    }

    #[Test]
    public function aWriteHookedValueIsStillLookedInside(): void
    {
        $this->expectRefused(['raw' => ['a' => 'x', 'anything' => true]], ['/data/attributes/raw/anything']);

        $model = self::model();
        $this->hydrate('PATCH', ['raw' => ['a' => 'x']], $model);
        self::assertSame(['a' => 'x'], $model->raw);
    }

    #[Test]
    public function aMemberNameIsEscapedInItsPointerAndNamedPlainInItsDetail(): void
    {
        $errors = (new AttributeMemberUnrecognized(['/data/attributes/meta/a~1b']))->getErrors();

        self::assertCount(1, $errors);
        $error = $errors[0]->transform();
        self::assertSame('ATTRIBUTE_MEMBER_UNRECOGNIZED', $error['code']);
        self::assertSame(['pointer' => '/data/attributes/meta/a~1b'], $error['source']);
        self::assertSame("The resource does not declare the member 'a/b'!", $error['detail']);
        self::assertSame(['/data/attributes/meta/a~1b'], UndeclaredAttributeMembers::in([Obj::make('meta')->fields(Str::make('a'))->build()], ['meta' => ['a/b' => 1]]));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $pointers
     */
    private function expectRefused(array $attributes, array $pointers): void
    {
        foreach (['POST', 'PATCH'] as $method) {
            try {
                $this->hydrate($method, $attributes, self::model());
                self::fail('Expected AttributeMemberUnrecognized on ' . $method);
            } catch (AttributeMemberUnrecognized $e) {
                self::assertSame($pointers, $e->pointers, $method);
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function hydrate(string $method, array $attributes, \stdClass $model): mixed
    {
        $data = ['type' => 'closed-widgets', 'attributes' => $attributes];
        if ($method === 'PATCH') {
            $data['id'] = '1';
        }
        $request = new JsonApiRequest(
            (new ServerRequest($method, '/closed-widgets', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody(['data' => $data]),
        );

        return Server::make()->register(ClosedWidget::class)->resourceFor('closed-widgets')->hydrate($request, $model);
    }

    private static function model(): \stdClass
    {
        return (object) ['id' => '1', 'title' => 'Old', 'views' => 1, 'meta' => [], 'block' => null, 'steps' => [], 'raw' => null, 'secret' => null];
    }
}

final class ClosedWidget extends AbstractResource
{
    public static string $type = 'closed-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('views')->readOnly(),
            Str::make('secret')->hidden(),
            Obj::make('meta')->fields(Str::make('a'), Str::make('b')->readOnly()),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('circle', Str::make('radius'))
                ->variant('square', Str::make('side')),
            ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op'))),
            Obj::make('raw')->fields(Str::make('a'))->fillUsing(static function (mixed $model, mixed $value): mixed {
                \assert($model instanceof \stdClass);
                $model->raw = $value;

                return $model;
            }),
        ];
    }
}
