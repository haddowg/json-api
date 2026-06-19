<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two complementary correctness layers for the projected schemas.
 *
 * **1. Meta-validation (structural).** Every projected schema is validated against
 * the **official JSON Schema 2020-12 meta-schema** — the core dialect plus its
 * referenced vocabulary meta-schemas, vendored under `Fixture/meta-schema/`
 * (`opis/json-schema` ships the 2020-12 *parser* but no meta-schema JSON, so the
 * documents are vendored and registered by their canonical `$id`). This is the
 * spec §10 acceptance criterion ("every emitted document validates against the
 * meta-schema") and catches a structurally malformed schema (e.g. a non-integer
 * `minLength`, a non-string `enum` member shape, a bad `type`).
 *
 * Note the meta-schema is **deliberately permissive about unknown keywords** (the
 * 2020-12 extension-vocabulary design — no `unevaluatedProperties: false` at the
 * document root), so meta-validation alone does *not* reject a non-standard
 * keyword that a consumer would silently ignore. That class of defect is the job
 * of layer 2.
 *
 * **2. Instance round-trip (behavioural).** Each projected schema is registered in
 * an opis {@see Validator} (so opis parses it under the 2020-12 dialect — a parse
 * failure surfaces here) and a **valid** and an **invalid** instance are
 * round-tripped through it, asserting accept/reject. This is what actually proves a
 * keyword *does the right thing* (and is the round-trip correctness anchor of D11),
 * so it covers the cases meta-validation cannot — including a nullable enum
 * accepting `null` and a fixed date bound carrying no silently-ignored keyword.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(Schema::class)]
#[Group('spec:document-structure')]
final class SchemaProjectorMetaValidationTest extends TestCase
{
    private const META_SCHEMA_ID = 'https://json-schema.org/draft/2020-12/schema';

    private function projector(): SchemaProjector
    {
        return new SchemaProjector();
    }

    /**
     * A validator with the vendored JSON Schema 2020-12 meta-schema documents
     * registered by their canonical `$id` (so `$ref`/`$dynamicRef` resolve).
     */
    private function metaValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $base = __DIR__ . '/Fixture/meta-schema/';
        $documents = [
            'schema.json',
            'meta/core.json',
            'meta/applicator.json',
            'meta/unevaluated.json',
            'meta/validation.json',
            'meta/meta-data.json',
            'meta/format-annotation.json',
            'meta/content.json',
        ];
        foreach ($documents as $document) {
            $raw = \file_get_contents($base . $document);
            self::assertIsString($raw);
            $decoded = \json_decode($raw);
            self::assertInstanceOf(\stdClass::class, $decoded);
            self::assertIsString($decoded->{'$id'} ?? null);
            $resolver->registerRaw($decoded, $decoded->{'$id'});
        }

        return $validator;
    }

    /**
     * Asserts a projected schema is itself a valid JSON Schema 2020-12 document.
     */
    private function assertValidSchemaDocument(Schema $schema): void
    {
        $result = $this->metaValidator()->validate($schema->toJson(), self::META_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'Projected schema is not a valid JSON Schema 2020-12 document: ' . \json_encode($schema->toArray()),
        );
    }

    /**
     * Validates `$data` against `$schema` (a projected schema node), returning the
     * opis error tree (null = valid). Registering and validating exercises opis's
     * 2020-12 parser on the projected schema's `stdClass` tree (so an empty schema
     * `{}` is a JSON object, not an array).
     */
    private function validate(Schema $schema, mixed $data): ?ValidationError
    {
        // Every projected schema is first asserted to be a well-formed 2020-12
        // schema document, then exercised behaviourally below.
        $this->assertValidSchemaDocument($schema);

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $schemaObject = $schema->toJson();
        $id = 'urn:haddowg:jsonapi:test:' . \hash('xxh128', \serialize($schema->toArray()));
        $schemaObject->{'$id'} = $id;
        $resolver->registerRaw($schemaObject, $id);

        return $validator->validate(Helper::toJSON($data), $id)->error();
    }

    #[Test]
    public function aConstrainedStringSchemaAcceptsValidAndRejectsInvalid(): void
    {
        $schema = $this->projector()->projectField(Str::make('name')->minLength(2)->maxLength(5));

        self::assertNull($this->validate($schema, 'Ada'));
        self::assertNotNull($this->validate($schema, 'x'));      // too short
        self::assertNotNull($this->validate($schema, 'far too long'));
    }

    #[Test]
    public function anEmailFormatSchemaParsesAndValidates(): void
    {
        $schema = $this->projector()->projectField(Email::make('email'));

        self::assertNull($this->validate($schema, 'ada@example.com'));
        self::assertNotNull($this->validate($schema, 'not-an-email'));
    }

    #[Test]
    public function aNullableIntegerSchemaAcceptsNullAndAnInteger(): void
    {
        $schema = $this->projector()->projectField(Integer::make('age')->min(0)->nullable());

        self::assertNull($this->validate($schema, 42));
        self::assertNull($this->validate($schema, null));
        self::assertNotNull($this->validate($schema, -1));       // below minimum
        self::assertNotNull($this->validate($schema, 'nope'));
    }

    #[Test]
    public function anEnumSchemaParsesDespiteVendorExtensions(): void
    {
        $schema = $this->projector()->projectField(Str::make('status')->enum(Status::class));

        self::assertNull($this->validate($schema, 'draft'));
        self::assertNotNull($this->validate($schema, 'unknown'));
    }

    #[Test]
    public function aNullableEnumSchemaAcceptsItsNullValue(): void
    {
        // The regression for finding 2: without `null` in `enum`, opis rejects the
        // field's own legitimate null even though the type union allows it.
        $schema = $this->projector()->projectField(Str::make('status')->enum(Status::class)->nullable());

        self::assertNull($this->validate($schema, 'draft'));
        self::assertNull($this->validate($schema, null));
        self::assertNotNull($this->validate($schema, 'unknown'));
    }

    #[Test]
    public function aFixedDateBoundEmitsNoSilentlyIgnoredKeyword(): void
    {
        // The regression for finding 1: the projected schema must not carry a
        // bound keyword opis silently ignores. A date-time a year before the
        // declared lower bound therefore round-trips as VALID (the bound lives in
        // the description, not as an unenforced keyword) — proving no wrong keyword
        // was emitted. The schema also meta-validates as a clean 2020-12 document.
        $schema = $this->projector()->projectField(
            DateTime::make('when')->after(new \DateTimeImmutable('2020-06-01T00:00:00+00:00')),
        );

        self::assertSame('date-time', $schema->get('format'));
        self::assertNull($schema->get('formatMinimum'));
        self::assertNull($schema->get('minimum'));
        self::assertNull($this->validate($schema, '2019-01-01T00:00:00+00:00'));
        self::assertNotNull($this->validate($schema, 'not-a-date-time'));
    }

    #[Test]
    public function anArrayListWithUniqueItemsValidates(): void
    {
        $schema = $this->projector()->projectField(ArrayList::make('tags')->minItems(1)->uniqueItems());

        self::assertNull($this->validate($schema, ['a', 'b']));
        self::assertNotNull($this->validate($schema, []));            // below minItems
        self::assertNotNull($this->validate($schema, ['a', 'a']));    // not unique
    }

    #[Test]
    public function aMapWithARequiredChildEnforcesItOnCreate(): void
    {
        // The regression for finding 4: the nested object schema must restate a
        // required child so a write omitting it is rejected, matching the runtime
        // nested-attribute validation cascade.
        $field = Map::make('address')->fields(
            Str::make('street')->required(),
            Str::make('line2'),
        );
        $schema = $this->projector()->projectField($field, creating: true);

        self::assertNull($this->validate($schema, ['street' => '1 High St', 'line2' => 'Flat 2']));
        self::assertNull($this->validate($schema, ['street' => '1 High St']));
        self::assertNotNull($this->validate($schema, ['line2' => 'Flat 2'])); // missing required street
    }

    #[Test]
    public function aProjectedResourceObjectValidatesAConformingResource(): void
    {
        $fields = [
            Id::make(),
            Str::make('name')->required()->maxLength(50),
            Str::make('status')->enum(Status::class),
        ];
        $schema = $this->projector()->projectResourceObject('articles', $fields);

        $valid = [
            'type' => 'articles',
            'id' => '1',
            'attributes' => ['name' => 'Hello', 'status' => 'draft'],
        ];
        self::assertNull($this->validate($schema, $valid));

        // Wrong type const → rejected.
        self::assertNotNull($this->validate($schema, ['type' => 'authors', 'id' => '1'] + ['attributes' => []]));
    }
}
