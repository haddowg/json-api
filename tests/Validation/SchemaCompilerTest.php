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

    /**
     * The compiled schema as a nested associative array (round-tripped through
     * JSON so deep structure is assertable without dynamic-property access).
     *
     * @return array<string, mixed>
     */
    private function compileToArray(bool $creating): array
    {
        $json = \json_encode($this->compiler()->compile($this->resource(), $creating), \JSON_THROW_ON_ERROR);
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Walks a nested array by key path, narrowing at each step, and returns the
     * scalar/array value at the leaf (so callers can assert against it without
     * tripping `offsetAccess`/`mixed` analysis).
     *
     * @param array<string, mixed> $schema
     */
    private function at(array $schema, string ...$keys): mixed
    {
        $cursor = $schema;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Like {@see at()} but asserts (and types) the leaf as a list, for use as an
     * `assertContains` haystack.
     *
     * @param array<string, mixed> $schema
     * @return list<mixed>
     */
    private function listAt(array $schema, string ...$keys): array
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsArray($value);

        return \array_values($value);
    }

    #[Test]
    public function compiledCreateSchemaProducesTighteningStructure(): void
    {
        $schema = $this->compileToArray(creating: true);

        self::assertSame('object', $this->at($schema, 'type'));
        self::assertSame('object', $this->at($schema, 'properties', 'data', 'type'));

        $required = $this->listAt($schema, 'properties', 'data', 'properties', 'attributes', 'required');
        self::assertContains('name', $required);
        self::assertContains('email', $required);
        self::assertContains('createOnly', $required);

        self::assertSame(50, $this->at($schema, 'properties', 'data', 'properties', 'attributes', 'properties', 'name', 'maxLength'));
        self::assertSame('email', $this->at($schema, 'properties', 'data', 'properties', 'attributes', 'properties', 'email', 'format'));
        self::assertSame(['active', 'inactive'], $this->at($schema, 'properties', 'data', 'properties', 'attributes', 'properties', 'status', 'enum'));
        self::assertSame(['integer', 'null'], $this->at($schema, 'properties', 'data', 'properties', 'attributes', 'properties', 'age', 'type'));

        $relRequired = $this->listAt($schema, 'properties', 'data', 'properties', 'relationships', 'required');
        self::assertContains('team', $relRequired);
        self::assertSame(
            ['teams'],
            $this->at($schema, 'properties', 'data', 'properties', 'relationships', 'properties', 'team', 'properties', 'data', 'properties', 'type', 'enum'),
        );
    }

    #[Test]
    public function compiledUpdateSchemaOmitsRequiredArrays(): void
    {
        $schema = $this->compileToArray(creating: false);
        $attributes = $this->at($schema, 'properties', 'data', 'properties', 'attributes');
        self::assertIsArray($attributes);

        self::assertArrayNotHasKey('required', $attributes);
        // Value constraints still apply on update.
        self::assertSame(50, $this->at($schema, 'properties', 'data', 'properties', 'attributes', 'properties', 'name', 'maxLength'));
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
