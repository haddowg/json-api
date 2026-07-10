<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Resource\Constraint\Fixture\HexFormat;
use haddowg\JsonApi\Validation\SchemaCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The self-describing-constraint seam: a leaf constraint owns its JSON Schema
 * keyword via {@see ProvidesJsonSchema}, so both schema consumers — the OpenAPI
 * {@see SchemaProjector} and the body-validation {@see SchemaCompiler} — reduce
 * over one source of truth, and a consumer-defined constraint appears in both
 * with no core change.
 */
#[CoversClass(ProvidesJsonSchema::class)]
#[CoversClass(SchemaProjector::class)]
#[CoversClass(SchemaCompiler::class)]
#[CoversClass(Schema::class)]
#[Group('spec:document-structure')]
final class ProvidesJsonSchemaTest extends TestCase
{
    #[Test]
    public function the_interface_is_a_constraint(): void
    {
        self::assertInstanceOf(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface::class, new MinLength(1));
        self::assertInstanceOf(ProvidesJsonSchema::class, new MinLength(1));
    }

    /**
     * Every self-describing leaf constraint contributes exactly its own JSON
     * Schema keyword onto the accumulated node — the single mapping both the
     * projector and the compiler now consult.
     *
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('leafMappings')]
    public function a_leaf_constraint_contributes_its_own_keyword(ProvidesJsonSchema $constraint, array $expected): void
    {
        self::assertSame($expected, $constraint->contribute(Schema::create())->toArray());
    }

    /**
     * @return array<string, array{0: ProvidesJsonSchema, 1: array<string, mixed>}>
     */
    public static function leafMappings(): array
    {
        return [
            'minLength' => [new MinLength(3), ['minLength' => 3]],
            'maxLength' => [new MaxLength(50), ['maxLength' => 50]],
            'minItems' => [new MinItems(1), ['minItems' => 1]],
            'maxItems' => [new MaxItems(10), ['maxItems' => 10]],
            'uniqueItems' => [new UniqueItems(), ['uniqueItems' => true]],
            'minProperties' => [new MinProperties(1), ['minProperties' => 1]],
            'maxProperties' => [new MaxProperties(4), ['maxProperties' => 4]],
            'minimum' => [new Min(0), ['minimum' => 0]],
            'maximum' => [new Max(150), ['maximum' => 150]],
            'exclusiveMinimum' => [new ExclusiveMin(0), ['exclusiveMinimum' => 0]],
            'exclusiveMaximum' => [new ExclusiveMax(10), ['exclusiveMaximum' => 10]],
            'multipleOf' => [new MultipleOf(2), ['multipleOf' => 2]],
            'pattern' => [new Pattern('^x'), ['pattern' => '^x']],
            'slug' => [new SlugFormat(), ['pattern' => SlugFormat::DEFAULT_PATTERN]],
            'ulid' => [new UlidFormat(), ['pattern' => Id::ULID_FORMAT_PATTERN]],
            'notIn' => [new NotIn(['x', 'y']), ['not' => ['enum' => ['x', 'y']]]],
            'email' => [new EmailFormat(), ['format' => 'email']],
            'url' => [new UrlFormat(), ['format' => 'uri']],
            'uuid' => [new UuidFormat(), ['format' => 'uuid']],
            'ipv4' => [new IpFormat(), ['format' => 'ipv4']],
            'ipv6' => [new IpFormat(6), ['format' => 'ipv6']],
        ];
    }

    /**
     * A `Pattern` carrying an OpenAPI-only `documentsAs` type still contributes its
     * plain `pattern` from {@see ProvidesJsonSchema} — the projector overlays the
     * type override itself, so the constraint's own contribution is unchanged.
     */
    #[Test]
    public function a_pattern_contributes_its_regex_regardless_of_documents_as(): void
    {
        $constraint = new Pattern('^-?[0-9]+$', documentsAs: 'number');

        self::assertSame(['pattern' => '^-?[0-9]+$'], $constraint->contribute(Schema::create())->toArray());
    }

    /**
     * The headline: a constraint outside core's vocabulary, attached via
     * `->constrain()`, appears in the projected OpenAPI schema — standard keyword
     * and vendor extension — with no change to the projector.
     */
    #[Test]
    public function the_openapi_projector_honours_a_consumer_defined_constraint(): void
    {
        $schema = (new SchemaProjector())->projectField(Str::make('token')->constrain(new HexFormat())->build())->toArray();

        self::assertSame(HexFormat::PATTERN, $schema['pattern']);
        self::assertTrue($schema['x-hex']);
    }

    /**
     * …and the same consumer-defined constraint tightens the body-validation
     * schema too — one seam, both consumers.
     */
    #[Test]
    public function the_body_validation_compiler_honours_a_consumer_defined_constraint(): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'tokens';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('token')->constrain(new HexFormat())->build(),
                ];
            }
        };

        $json = \json_encode((new SchemaCompiler())->compile($resource, true), \JSON_THROW_ON_ERROR);
        $compiled = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($compiled);

        $path = ['properties', 'data', 'properties', 'attributes', 'properties', 'token'];
        self::assertSame(HexFormat::PATTERN, $this->at($compiled, ...[...$path, 'pattern']));
        self::assertTrue($this->at($compiled, ...[...$path, 'x-hex']));
    }

    /**
     * A composite (`sequentially`/`each`/`atLeastOneOf`) is not itself
     * self-describing, but it recurses *through* the seam: a consumer-defined
     * `ProvidesJsonSchema` constraint nested inside one still contributes its
     * keyword — to both the projected and the compiled schema.
     */
    #[Test]
    public function a_composite_composes_a_consumer_defined_constraint_through_the_seam(): void
    {
        $field = Str::make('token')->sequentially(new HexFormat(), new MinLength(3))->build();

        $projected = (new SchemaProjector())->projectField($field)->toArray();
        self::assertSame(HexFormat::PATTERN, $projected['pattern']);
        self::assertTrue($projected['x-hex']);
        self::assertSame(3, $projected['minLength']);

        $resource = new class extends AbstractResource {
            public static string $type = 'tokens';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('token')->sequentially(new HexFormat(), new MinLength(3))->build(),
                ];
            }
        };

        $json = \json_encode((new SchemaCompiler())->compile($resource, true), \JSON_THROW_ON_ERROR);
        $compiled = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($compiled);

        $token = ['properties', 'data', 'properties', 'attributes', 'properties', 'token'];
        self::assertSame(HexFormat::PATTERN, $this->at($compiled, ...[...$token, 'pattern']));
        self::assertTrue($this->at($compiled, ...[...$token, 'x-hex']));
        self::assertSame(3, $this->at($compiled, ...[...$token, 'minLength']));
    }

    /**
     * Walks a decoded schema value by key path, narrowing `mixed` at each hop.
     */
    private function at(mixed $schema, string ...$path): mixed
    {
        $node = $schema;
        foreach ($path as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }
}
