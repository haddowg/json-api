<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaCompiler::class)]
#[Group('spec:document-structure')]
final class SchemaCompilerTest extends TestCase
{
    private function validator(): DocumentValidator
    {
        return new DocumentValidator(new VendoredSchemaProvider());
    }

    private function compiler(): SchemaCompiler
    {
        return new SchemaCompiler();
    }

    private function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'authors';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('name')->required()->minLength(1)->maxLength(50),
                    Email::make('email')->required(),
                    Integer::make('age')->min(0)->max(150)->nullable(),
                    Str::make('status')->in(['active', 'inactive']),
                    Str::make('createOnly')->requiredOnCreate(),
                    BelongsTo::make('team')->type('teams')->required(),
                ];
            }
        };
    }

    #[Test]
    public function compiledCreateSchemaProducesTighteningStructure(): void
    {
        $schema = $this->compiler()->compile($this->resource(), creating: true);

        // Top level: { type, properties: { data: { type, properties: {…} } } }
        self::assertSame('object', $schema->type);
        $data = $schema->properties->data;
        self::assertSame('object', $data->type);

        $attributes = $data->properties->attributes;
        self::assertContains('name', $attributes->required);
        self::assertContains('email', $attributes->required);
        self::assertContains('createOnly', $attributes->required);
        self::assertSame(50, $attributes->properties->name->maxLength);
        self::assertSame('email', $attributes->properties->email->format);
        self::assertSame(['active', 'inactive'], $attributes->properties->status->enum);
        self::assertSame(['integer', 'null'], $attributes->properties->age->type);

        $relationships = $data->properties->relationships;
        self::assertContains('team', $relationships->required);
        self::assertSame(['teams'], $relationships->properties->team->properties->data->properties->type->enum);
    }

    #[Test]
    public function compiledUpdateSchemaOmitsRequiredArrays(): void
    {
        $schema = $this->compiler()->compile($this->resource(), creating: false);
        $attributes = $schema->properties->data->properties->attributes;

        self::assertObjectNotHasProperty('required', $attributes);
        // Value constraints still apply on update.
        self::assertSame(50, $attributes->properties->name->maxLength);
    }

    #[Test]
    public function perResourceSchemaAcceptsAValidCreateBody(): void
    {
        $compiled = $this->compiler()->compile($this->resource(), creating: true);
        $body = [
            'data' => [
                'type' => 'authors',
                'attributes' => [
                    'name' => 'Ada',
                    'email' => 'ada@example.com',
                    'createOnly' => 'x',
                    'status' => 'active',
                ],
                'relationships' => [
                    'team' => ['data' => ['type' => 'teams', 'id' => '1']],
                ],
            ],
        ];

        $this->validator()->validateRequest($body, [$compiled]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function perResourceSchemaRejectsAnInvalidAttributeWithCorrectPointer(): void
    {
        $compiled = $this->compiler()->compile($this->resource(), creating: true);
        $body = [
            'data' => [
                'type' => 'authors',
                'attributes' => [
                    'name' => '',                 // violates minLength: 1
                    'email' => 'not-an-email',    // violates format: email
                    'createOnly' => 'x',
                ],
                'relationships' => [
                    'team' => ['data' => ['type' => 'teams', 'id' => '1']],
                ],
            ],
        ];

        try {
            $this->validator()->validateRequest($body, [$compiled]);
            self::fail('Expected RequestBodyInvalidJsonApi.');
        } catch (RequestBodyInvalidJsonApi $e) {
            $pointers = \array_column($e->validationErrors, 'property');
            self::assertContains('/data/attributes/name', $pointers);
            self::assertContains('/data/attributes/email', $pointers);
        }
    }

    #[Test]
    public function perResourceSchemaRejectsAMissingRequiredAttributeOnCreate(): void
    {
        $compiled = $this->compiler()->compile($this->resource(), creating: true);
        $body = [
            'data' => [
                'type' => 'authors',
                'attributes' => ['name' => 'Ada', 'createOnly' => 'x'],
                'relationships' => [
                    'team' => ['data' => ['type' => 'teams', 'id' => '1']],
                ],
            ],
        ];

        $this->expectException(RequestBodyInvalidJsonApi::class);
        $this->validator()->validateRequest($body, [$compiled]);
    }

    #[Test]
    public function updateSchemaAllowsAPartialBody(): void
    {
        $compiled = $this->compiler()->compile($this->resource(), creating: false);
        $body = [
            'data' => [
                'type' => 'authors',
                'id' => '1',
                'attributes' => ['name' => 'Ada Lovelace'],
            ],
        ];

        $this->validator()->validateRequest($body, [$compiled]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function updateSchemaStillRejectsAnInvalidSuppliedValue(): void
    {
        $compiled = $this->compiler()->compile($this->resource(), creating: false);
        $body = [
            'data' => [
                'type' => 'authors',
                'id' => '1',
                'attributes' => ['status' => 'archived'], // not in enum
            ],
        ];

        $this->expectException(RequestBodyInvalidJsonApi::class);
        $this->validator()->validateRequest($body, [$compiled]);
    }
}
