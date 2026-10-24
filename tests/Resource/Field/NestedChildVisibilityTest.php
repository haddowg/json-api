<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A child of a nested object follows the hidden, write-only and read-only rules a
 * top-level attribute does, including their per-request forms.
 */
#[CoversClass(Map::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
final class NestedChildVisibilityTest extends TestCase
{
    /**
     * @return list<FieldInterface>
     */
    private static function children(): array
    {
        $nonAdmin = static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin';

        return [
            Str::make('name')->build(),
            Str::make('internal')->hidden()->build(),
            Str::make('note')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => $nonAdmin($request))->build(),
            Str::make('token')->writeOnly($nonAdmin)->build(),
            Str::make('locked')->readOnly($nonAdmin)->build(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function values(): array
    {
        return ['name' => 'n', 'internal' => 'i', 'note' => 'o', 'token' => 't', 'locked' => 'l'];
    }

    #[Test]
    public function aMapRendersOnlyTheChildrenVisibleToTheRequest(): void
    {
        $field = Map::make('profile')->fields(...self::children())->build();

        self::assertSame(['name' => 'n', 'locked' => 'l'], $field->serialize(self::values(), $this->guest(), 'profile'));
        self::assertSame(
            ['name' => 'n', 'note' => 'o', 'token' => 't', 'locked' => 'l'],
            $field->serialize(self::values(), $this->admin(), 'profile'),
        );
    }

    #[Test]
    public function anObjRendersOnlyTheChildrenVisibleToTheRequest(): void
    {
        $field = Obj::make('profile')->fields(...self::children())->build();
        $model = ['profile' => self::values()];

        self::assertSame(['name' => 'n', 'locked' => 'l'], $field->serialize($model, $this->guest(), 'profile'));
        self::assertSame(
            ['name' => 'n', 'note' => 'o', 'token' => 't', 'locked' => 'l'],
            $field->serialize($model, $this->admin(), 'profile'),
        );
    }

    #[Test]
    public function aOneOfVariantRendersOnlyTheChildrenVisibleToTheRequest(): void
    {
        $field = OneOf::make('profile')->discriminator('kind')->variant('person', ...self::children())->build();
        $model = ['profile' => ['kind' => 'person', ...self::values()]];

        self::assertSame(['kind' => 'person', 'name' => 'n', 'locked' => 'l'], $field->serialize($model, $this->guest(), 'profile'));
        self::assertSame(
            ['kind' => 'person', 'name' => 'n', 'note' => 'o', 'token' => 't', 'locked' => 'l'],
            $field->serialize($model, $this->admin(), 'profile'),
        );
    }

    #[Test]
    public function aMapHydratesNeitherAHiddenChildNorOneReadOnlyForTheRequest(): void
    {
        $field = Map::make('profile')->fields(...self::children())->build();
        $blank = ['name' => '', 'internal' => '', 'note' => '', 'token' => '', 'locked' => ''];

        self::assertSame(
            ['name' => 'n', 'internal' => '', 'note' => 'o', 'token' => 't', 'locked' => ''],
            $field->hydrate($blank, self::values(), [], $this->guest(), true),
        );
        self::assertSame(
            ['name' => 'n', 'internal' => '', 'note' => 'o', 'token' => 't', 'locked' => 'l'],
            $field->hydrate($blank, self::values(), [], $this->admin(), true),
        );
    }

    #[Test]
    public function anObjHydratesNeitherAHiddenChildNorOneReadOnlyForTheRequest(): void
    {
        $field = Obj::make('profile')->fields(...self::children())->build();

        self::assertSame(
            ['profile' => ['name' => 'n', 'note' => 'o', 'token' => 't']],
            $field->hydrate([], self::values(), [], $this->guest(), true),
        );
        self::assertSame(
            ['profile' => ['name' => 'n', 'note' => 'o', 'token' => 't', 'locked' => 'l']],
            $field->hydrate([], self::values(), [], $this->admin(), true),
        );
    }

    #[Test]
    public function aOneOfVariantHydratesNeitherAHiddenChildNorOneReadOnlyForTheRequest(): void
    {
        $field = OneOf::make('profile')->discriminator('kind')->variant('person', ...self::children())->build();

        self::assertSame(
            ['profile' => ['kind' => 'person', 'name' => 'n', 'note' => 'o', 'token' => 't']],
            $field->hydrate([], ['kind' => 'person', ...self::values()], [], $this->guest(), true),
        );
    }

    private function admin(): StubJsonApiRequest
    {
        return StubJsonApiRequest::create([], ['X-Role' => 'admin']);
    }

    private function guest(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}
