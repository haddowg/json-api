<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\OneOfBuilder;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\NestedFieldsets;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A union whose discriminator is bound to an enum takes the enum's cases as its variants,
 * needs one for every case, and hands hooks and the hydrated value the case.
 */
#[CoversClass(OneOf::class)]
#[CoversClass(OneOfBuilder::class)]
final class OneOfEnumDiscriminatorTest extends TestCase
{
    /**
     * @return OneOfBuilder<array<string, mixed>, BlockKind>
     */
    private static function blocks(): OneOfBuilder
    {
        return OneOf::make('block')->discriminator('type', BlockKind::class)
            ->variants(static fn(BlockKind $kind): ?Obj => match ($kind) {
                BlockKind::Text => Obj::make('text')->fields(Str::make('title'), Str::make('body'))->nestedFieldset(pattern: 'title|body')->build(),
                BlockKind::Image => Obj::make('image')->fields(Str::make('url'))->build(),
                BlockKind::Divider => null,
            });
    }

    #[Test]
    public function variantsDeclaresOneVariantPerCase(): void
    {
        $union = self::blocks()->build();

        self::assertSame(['text', 'image', 'divider'], \array_keys($union->variants()));
        self::assertSame([], $union->variants()['divider']->children());
        self::assertNotNull($union->variants()['text']->nestedFieldsetKeys());
        self::assertSame(BlockKind::class, $union->discriminatorEnum());
    }

    #[Test]
    public function aCaseWithoutAVariantIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('whose case Divider has no variant');

        OneOf::make('block')->discriminator('type', BlockKind::class)
            ->variant(BlockKind::Text, Str::make('title'))
            ->variant(BlockKind::Image, Str::make('url'))
            ->build();
    }

    #[Test]
    public function aVariantNamedOtherThanByTheBoundEnumsCaseIsRefused(): void
    {
        $refused = [
            // @phpstan-ignore argument.type (the refusal under test)
            static fn(): mixed => OneOf::make('block')->discriminator('type', BlockKind::class)->variant('text'),
            // @phpstan-ignore argument.type (the refusal under test)
            static fn(): mixed => OneOf::make('block')->discriminator('type')->variant(BlockKind::Text),
            static fn(): mixed => OneOf::make('block')->discriminator('type')->variants(static fn(): null => null),
        ];
        foreach ($refused as $declare) {
            try {
                $declare();
                self::fail('Expected the declaration to be refused.');
            } catch (\InvalidArgumentException|\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function anIntBackedEnumIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OneOf::make('block')->discriminator('type', \haddowg\JsonApi\Tests\OpenApi\Fixture\Priority::class);
    }

    #[Test]
    public function aVariantMemberNamedLikeTheDiscriminatorIsRefused(): void
    {
        foreach ([
            static fn(): OneOf => OneOf::make('block')->discriminator('type')->variant('text', Str::make('type'))->build(),
            static fn(): OneOf => OneOf::make('block')->discriminator('type')->variant('text', Obj::make('text')->fields(Str::make('type')))->build(),
        ] as $declare) {
            try {
                $declare();
                self::fail('Expected the variant to be refused.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('declares a member named "type", which is its discriminator', $e->getMessage());
            }
        }
    }

    #[Test]
    public function aSingleObjNamedForTheVariantIsTheVariantAndAnyOtherIsAChild(): void
    {
        $union = OneOf::make('step')->discriminator('operation')
            ->variant('trim', Obj::make('trim')->fields(Str::make('side')))
            ->variant('find_replace', Obj::make('params')->fields(Str::make('find')))
            ->build();

        self::assertSame(['side'], \array_map(static fn($child): string => $child->name(), $union->variants()['trim']->children()));
        self::assertSame(['params'], \array_map(static fn($child): string => $child->name(), $union->variants()['find_replace']->children()));
    }

    #[Test]
    public function theHydratedValueAndAFillHookCarryTheCase(): void
    {
        $request = new JsonApiRequest(new ServerRequest('PATCH', '/'));
        $model = self::blocks()->build()->hydrate(['block' => null], ['type' => 'text', 'title' => 'Intro'], [], $request, true);

        self::assertIsArray($model);
        self::assertSame(['type' => BlockKind::Text, 'title' => 'Intro'], $model['block']);

        $received = null;
        self::blocks()->fillUsing(static function (mixed $model, mixed $value) use (&$received): mixed {
            $received = $value;

            return $model;
        })->build()->hydrate([], ['type' => 'image', 'url' => '/a.png'], [], $request, true);
        self::assertSame(['type' => BlockKind::Image, 'url' => '/a.png'], $received);
    }

    #[Test]
    public function aStoredCaseRendersItsBackingValue(): void
    {
        $request = new JsonApiRequest(new ServerRequest('GET', '/'));
        $union = self::blocks()->build();

        self::assertSame(['type' => 'text', 'title' => 'Intro', 'body' => null], $union->serialize(['block' => ['type' => BlockKind::Text, 'title' => 'Intro']], $request, 'block'));
        self::assertSame(['type' => 'divider'], $union->serialize(['block' => ['type' => 'divider']], $request, 'block'));
    }

    #[Test]
    public function aSelectionOfAnEnumBoundUnionKeepsTheCasesValue(): void
    {
        $request = new JsonApiRequest(new ServerRequest('GET', '/'));
        $union = self::blocks()->build();
        $fieldset = NestedFieldsets::fieldset($union, ['title'], $request);

        self::assertNotNull($fieldset);
        self::assertSame(['type' => 'text', 'title' => 'Intro'], $union->serializeNestedFieldset(['block' => ['type' => BlockKind::Text, 'title' => 'Intro', 'body' => 'Hi']], $request, 'block', $fieldset));
        self::assertSame(['type' => 'image'], $union->serializeNestedFieldset(['block' => ['type' => BlockKind::Image, 'url' => '/a.png']], $request, 'block', $fieldset));
    }

    #[Test]
    public function theDiscriminatorPropertyReferencesTheEnumsComponent(): void
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(type: 'pages', fields: [Id::make()->build(), self::blocks()->build()])],
        ))->toArray();
        $schemas = self::arr(self::arr($document['components'] ?? null)['schemas'] ?? null);

        self::assertSame(['text', 'image', 'divider'], self::arr($schemas['BlockKind'] ?? null)['enum'] ?? null);
        $block = self::arr(self::arr(self::arr($schemas['PagesAttributes'] ?? null)['properties'] ?? null)['block'] ?? null);
        self::assertSame(['propertyName' => 'type'], $block['discriminator'] ?? null);
        $text = self::arr(self::arr($block['oneOf'] ?? null)[0] ?? null);
        self::assertSame(
            ['type' => 'string', 'const' => 'text', 'allOf' => [['$ref' => '#/components/schemas/BlockKind']]],
            self::arr($text['properties'] ?? null)['type'] ?? null,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
