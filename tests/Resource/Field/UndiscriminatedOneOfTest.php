<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\UndeclaredAttributeMembers;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A OneOf with no discriminator selects its variant by the signature member the value
 * carries, and every write replaces the whole value.
 */
#[CoversClass(OneOf::class)]
#[CoversClass(SchemaProjector::class)]
final class UndiscriminatedOneOfTest extends TestCase
{
    #[Test]
    public function aOneOfHasNoDiscriminatorUnlessOneIsNamed(): void
    {
        self::assertNull($this->shape()->discriminatorName());
        self::assertFalse($this->shape()->isDiscriminated());
        self::assertSame('kind', OneOf::make('block')->discriminator('kind')->variant('a')->build()->discriminatorName());
    }

    #[Test]
    public function eachVariantsSignatureIsTheRequiredMembersOnlyItDeclares(): void
    {
        $field = $this->shape();

        self::assertSame(['radius'], $field->signatureOf('circle'));
        self::assertSame(['width', 'height'], $field->signatureOf('rectangle'));
    }

    #[Test]
    public function aVariantWithoutASignatureIsRejectedAtBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('variant "square"');

        OneOf::make('shape')
            ->variant('circle', Integer::make('radius')->required())
            ->variant('square', Integer::make('side'), Str::make('label')->required())
            ->variant('labelled', Str::make('label')->required(), Integer::make('size')->required())
            ->build();
    }

    #[Test]
    public function aMemberRequiredOnlyOnCreateOrNeverRenderedCannotBeASignature(): void
    {
        foreach ([Integer::make('radius')->requiredOnCreate(), Integer::make('radius')->required()->writeOnly()] as $member) {
            try {
                OneOf::make('shape')->variant('circle', $member)->variant('dot', Integer::make('x')->required())->build();
                self::fail('Expected a LogicException');
            } catch (\LogicException $e) {
                self::assertStringContainsString('variant "circle"', $e->getMessage());
            }
        }
    }

    #[Test]
    public function theValuesSignatureSelectsTheVariant(): void
    {
        $field = $this->shape();

        self::assertSame('circle', $field->variantNameOf(['radius' => 1, 'label' => 'x']));
        self::assertSame('rectangle', $field->variantNameOf(['height' => 2]));
        self::assertSame('rectangle', $field->variantNameOf((object) ['width' => 2]));
        self::assertNull($field->variantNameOf(['radius' => 1, 'width' => 2]), 'two signatures select neither');
        self::assertNull($field->variantNameOf(['label' => 'x']), 'no signature selects nothing');
        self::assertNull($field->variantNameOf('circle'));
        self::assertNull($field->variantFor('circle'), 'there is no discriminator value to look up');
    }

    #[Test]
    public function aPatchReplacesTheWholeStoredValue(): void
    {
        $field = $this->shape();
        $stored = ['shape' => ['radius' => 1, 'label' => 'Old']];

        self::assertSame(['shape' => ['radius' => 5]], $field->hydrate($stored, ['radius' => 5], [], StubJsonApiRequest::create(), false));
        self::assertSame(['shape' => ['width' => 2, 'height' => 3]], $field->hydrate($stored, ['width' => 2, 'height' => 3], [], StubJsonApiRequest::create(), false));
    }

    #[Test]
    public function aValueSelectingNoVariantIsStoredRawForTheValidator(): void
    {
        $model = $this->shape()->hydrate([], ['radius' => 1, 'width' => 2], [], StubJsonApiRequest::create(), true);

        self::assertSame(['shape' => ['radius' => 1, 'width' => 2]], $model);
    }

    #[Test]
    public function theValueRendersWithoutADiscriminator(): void
    {
        $rendered = $this->shape()->serialize(['shape' => ['width' => 2, 'height' => 3]], StubJsonApiRequest::create(), 'shape');

        self::assertSame(['width' => 2, 'height' => 3], $rendered);
    }

    #[Test]
    public function undeclaredMembersAreFoundInsideTheSelectedVariant(): void
    {
        self::assertSame(
            ['/data/attributes/shape/colour'],
            UndeclaredAttributeMembers::in([$this->shape()], ['shape' => ['radius' => 1, 'colour' => 'red']]),
        );
    }

    #[Test]
    public function itProjectsClosedBranchesWithNoDiscriminatorAndAnUpdateRequiringTheWholeVariant(): void
    {
        $projector = new SchemaProjector();

        foreach ([RepresentationContext::Create, RepresentationContext::Update, RepresentationContext::Read] as $context) {
            $attributes = $projector->projectAttributes([$this->shape()], $context)->toArray();
            $properties = $attributes['properties'] ?? null;
            self::assertIsArray($properties);
            $schema = $properties['shape'] ?? null;
            self::assertIsArray($schema);

            self::assertArrayNotHasKey('discriminator', $schema, $context->name);
            self::assertSame([
                ['type' => 'object', 'properties' => ['radius' => ['type' => 'integer'], 'label' => ['type' => 'string']], 'required' => $context === RepresentationContext::Read ? ['radius', 'label'] : ['radius'], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['width' => ['type' => 'integer'], 'height' => ['type' => 'integer']], 'required' => ['width', 'height'], 'additionalProperties' => false],
            ], $this->withoutNullTypes($schema['oneOf'] ?? null), $context->name);
        }
    }

    private function shape(): OneOf
    {
        return OneOf::make('shape')
            ->variant('circle', Integer::make('radius')->required(), Str::make('label'))
            ->variant('rectangle', Integer::make('width')->required(), Integer::make('height')->required())
            ->build();
    }

    /**
     * A read projection admits `null` for a value the server does not guarantee; the
     * branches are compared on their shape, so each `null` is folded back out.
     *
     * @return list<mixed>
     */
    private function withoutNullTypes(mixed $branches): array
    {
        self::assertIsArray($branches);
        $json = (string) \json_encode($branches, \JSON_THROW_ON_ERROR);
        $json = \str_replace(['["integer","null"]', '["string","null"]'], ['"integer"', '"string"'], $json);
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return \array_values(\array_filter($decoded, static fn(mixed $branch): bool => $branch !== ['type' => 'null']));
    }
}
