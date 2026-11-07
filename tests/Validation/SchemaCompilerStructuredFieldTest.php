<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `Obj`, a `OneOf` and a structured list compile to the schema the OpenAPI projection
 * describes them with, so a request validates against their real shape.
 */
#[CoversClass(SchemaCompiler::class)]
#[Group('spec:document-structure')]
final class SchemaCompilerStructuredFieldTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validBodies(): iterable
    {
        yield 'an object' => [['settings' => ['theme' => 'dark']]];
        yield 'a discriminated variant' => [['block' => ['kind' => 'heading', 'text' => 'Hi']]];
        yield 'an undiscriminated variant' => [['shape' => ['width' => 2, 'height' => 3]]];
        yield 'a list of objects' => [['steps' => [['op' => 'trim'], ['op' => 'pad']]]];
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'an object member with the wrong type' => [['settings' => ['theme' => 3]]];
        yield 'an undeclared object member' => [['settings' => ['theme' => 'dark', 'colour' => 'red']]];
        yield 'an unknown discriminator' => [['block' => ['kind' => 'video']]];
        yield 'two variants\' signatures' => [['shape' => ['radius' => 1, 'width' => 2, 'height' => 3]]];
        yield 'an undiscriminated update missing a required member' => [['shape' => ['width' => 2]]];
        yield 'a list element with an undeclared member' => [['steps' => [['op' => 'trim', 'x' => 1]]]];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('validBodies')]
    public function aWellShapedValueValidatesOnUpdate(array $attributes): void
    {
        $this->validate($attributes);
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function aMisshapedValueIsRejectedOnUpdate(array $attributes): void
    {
        $this->expectException(RequestBodyInvalidJsonApi::class);
        $this->validate($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function validate(array $attributes): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'widgets';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Obj::make('settings')->fields(Str::make('theme')),
                    OneOf::make('block')->discriminator('kind')->variant('heading', Str::make('text')),
                    OneOf::make('shape')
                        ->variant('circle', Integer::make('radius')->required())
                        ->variant('rectangle', Integer::make('width')->required(), Integer::make('height')->required()),
                    ArrayList::make('steps')->of(Obj::make('step')->fields(Str::make('op')->required())),
                ];
            }
        };

        $body = ['data' => ['type' => 'widgets', 'id' => '1', 'attributes' => $attributes]];
        (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest($body, [(new SchemaCompiler())->compile($resource, false)]);
    }
}
