<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Constraint\Shape;
use haddowg\JsonApi\Validation\SchemaValueValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class SchemaValueValidatorTest extends TestCase
{
    private function contactSchema(): Schema
    {
        // A discriminated oneOf of an email shape and a phone shape.
        return Shape::oneOf(
            Schema::ofType('object')
                ->withProperties([
                    'kind' => Schema::ofType('string')->withConst('email'),
                    'address' => Schema::ofType('string')->withFormat('email'),
                ])
                ->withRequired(['kind', 'address']),
            Schema::ofType('object')
                ->withProperties([
                    'kind' => Schema::ofType('string')->withConst('phone'),
                    'number' => Schema::ofType('string'),
                ])
                ->withRequired(['kind', 'number']),
        )->discriminator('kind')->contribute(Schema::create());
    }

    #[Test]
    public function aValueMatchingAVariantYieldsNoErrors(): void
    {
        $errors = (new SchemaValueValidator())->validate(
            $this->contactSchema(),
            ['kind' => 'email', 'address' => 'ada@example.test'],
            '/data/attributes/contact',
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function aVariantMissingARequiredMemberYieldsA422Error(): void
    {
        // kind=email but no `address` — satisfies neither branch of the oneOf.
        $errors = (new SchemaValueValidator())->validate(
            $this->contactSchema(),
            ['kind' => 'email'],
            '/data/attributes/contact',
        );

        self::assertNotSame([], $errors);
        self::assertSame('422', $errors[0]->status);
        self::assertNotNull($errors[0]->source);
        self::assertStringStartsWith('/data/attributes/contact', (string) $errors[0]->source->pointer);
    }

    #[Test]
    public function anUnknownDiscriminatorYieldsA422Error(): void
    {
        $errors = (new SchemaValueValidator())->validate(
            $this->contactSchema(),
            ['kind' => 'fax', 'number' => '123'],
            '/data/attributes/contact',
        );

        self::assertNotSame([], $errors);
        self::assertSame('422', $errors[0]->status);
    }

    #[Test]
    public function aNestedMemberViolationCarriesTheChildPointer(): void
    {
        // A plain object schema with a nested required child, to assert the opis
        // instance pointer is appended to the prefix (`/…/contact/address`).
        $schema = Schema::ofType('object')
            ->withProperties([
                'address' => Schema::ofType('string')->withMinLength(5),
            ])
            ->withRequired(['address']);

        $errors = (new SchemaValueValidator())->validate(
            $schema,
            ['address' => 'a'], // too short
            '/data/attributes/contact',
        );

        self::assertNotSame([], $errors);
        self::assertNotNull($errors[0]->source);
        self::assertSame('/data/attributes/contact/address', (string) $errors[0]->source->pointer);
    }

    #[Test]
    public function anEmptySchemaNodeAcceptsAnyValue(): void
    {
        $schema = Schema::ofType('object')->withProperties(['anything' => Schema::create()]);

        self::assertSame([], (new SchemaValueValidator())->validate($schema, ['anything' => 1], '/data/attributes/bag'));
    }

    #[Test]
    public function aWholeValueViolationReportsExactlyThePrefix(): void
    {
        $schema = Schema::ofType('object')->withProperties(['theme' => Schema::ofType('string')]);

        $errors = (new SchemaValueValidator())->validate($schema, [], '/data/attributes/settings');

        self::assertSame(['/data/attributes/settings'], $this->pointers($errors));
    }

    #[Test]
    public function aWholeValueCompositeViolationReportsExactlyThePrefix(): void
    {
        // kind=fax matches no oneOf branch, so opis reports the failure at the value root.
        $errors = (new SchemaValueValidator())->validate(
            $this->contactSchema(),
            ['kind' => 'fax', 'number' => '123'],
            '/data/attributes/contact',
        );

        self::assertNotSame([], $errors);
        self::assertContains('/data/attributes/contact', $this->pointers($errors));
        foreach ($this->pointers($errors) as $pointer) {
            self::assertStringEndsNotWith('/', $pointer);
        }
    }

    #[Test]
    public function aNestedViolationReportsThePrefixPlusTheNestedPath(): void
    {
        $schema = Schema::ofType('object')->withProperties(['theme' => Schema::ofType('string')]);

        $errors = (new SchemaValueValidator())->validate($schema, ['theme' => 1], '/data/attributes/settings');

        self::assertSame(['/data/attributes/settings/theme'], $this->pointers($errors));
    }

    #[Test]
    public function anEmptyPrefixTreatsTheValueAsTheDocumentRoot(): void
    {
        // RFC 6901: `''` is the whole document, so a root violation carries the empty
        // pointer and a nested one the plain child path.
        $schema = Schema::ofType('object')->withProperties(['theme' => Schema::ofType('string')]);
        $validator = new SchemaValueValidator();

        self::assertSame([''], $this->pointers($validator->validate($schema, [], '')));
        self::assertSame(['/theme'], $this->pointers($validator->validate($schema, ['theme' => 1], '')));
    }

    /**
     * @param list<\haddowg\JsonApi\Schema\Error\Error> $errors
     *
     * @return list<string>
     */
    private function pointers(array $errors): array
    {
        return \array_map(static fn($error): string => (string) $error->source?->pointer, $errors);
    }
}
