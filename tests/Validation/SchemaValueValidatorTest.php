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
}
