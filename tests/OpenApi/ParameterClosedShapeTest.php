<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Header;
use haddowg\JsonApi\OpenApi\Parameter;
use haddowg\JsonApi\OpenApi\ParameterLocation;
use haddowg\JsonApi\OpenApi\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The vendored OAS 3.1 meta-schema relaxes `unevaluatedProperties: false` → `true`
 * on the `parameter` / `header` defs (an `opis/json-schema` 2.6 annotation-propagation
 * limitation, documented in the fixture README + {@see OpenApiMetaValidationTest}), so
 * the meta-validation cannot itself reject a parameter/header carrying an *unknown*
 * member.
 *
 * That relaxation is **benign for generated documents**: the projector emits a
 * parameter/header only through the typed {@see Parameter} / {@see Header} value
 * objects, whose serialization is a **closed shape** — it can only emit the members
 * the OAS Parameter/Header Objects define (`name`, `in`, `description`, `required`,
 * `deprecated`, `schema`), and no other. This test pins that closed shape, so an
 * unevaluated-property regression in the VO model is caught here even though the
 * relaxed meta-schema would let it pass.
 */
#[CoversClass(Parameter::class)]
#[CoversClass(Header::class)]
#[Group('spec:document-structure')]
final class ParameterClosedShapeTest extends TestCase
{
    /**
     * The only members an OAS 3.1 Parameter Object may carry in the schema-style form
     * the projector emits (the `content`/serialization members are not modelled).
     *
     * @var list<string>
     */
    private const PARAMETER_MEMBERS = ['name', 'in', 'description', 'required', 'deprecated', 'schema'];

    /**
     * The only members an OAS 3.1 Header Object may carry in the schema-style form.
     *
     * @var list<string>
     */
    private const HEADER_MEMBERS = ['description', 'required', 'deprecated', 'schema'];

    #[Test]
    public function aFullyPopulatedQueryParameterEmitsOnlyParameterObjectMembers(): void
    {
        $parameter = new Parameter(
            name: 'filter[status]',
            in: ParameterLocation::Query,
            description: 'Filter by status.',
            required: false,
            deprecated: true,
            schema: Schema::ofType('string'),
        );

        $this->assertOnlyMembers(self::PARAMETER_MEMBERS, $parameter->toArray());
        $this->assertOnlyMembers(self::PARAMETER_MEMBERS, (array) $parameter->toJson());
    }

    #[Test]
    public function aPathParameterFactoryEmitsOnlyParameterObjectMembers(): void
    {
        $parameter = Parameter::path('id', Schema::ofType('string'), 'The resource identifier.');

        $this->assertOnlyMembers(self::PARAMETER_MEMBERS, $parameter->toArray());
        // A path parameter is implicitly required.
        self::assertTrue($parameter->toArray()['required']);
    }

    #[Test]
    public function aMinimalQueryParameterEmitsNoOptionalMembers(): void
    {
        $parameter = Parameter::query('sort');

        self::assertSame(['name', 'in'], \array_keys($parameter->toArray()));
    }

    #[Test]
    public function aFullyPopulatedHeaderEmitsOnlyHeaderObjectMembers(): void
    {
        $header = new Header(
            description: 'The URL of the created resource.',
            required: true,
            deprecated: false,
            schema: Schema::ofType('string')->withFormat('uri-reference'),
        );

        $this->assertOnlyMembers(self::HEADER_MEMBERS, $header->toArray());
        $this->assertOnlyMembers(self::HEADER_MEMBERS, (array) $header->toJson());
    }

    /**
     * Asserts the array's keys are a subset of the allowed member set (no unknown
     * member can ever be emitted — the VO has no path to one).
     *
     * @param list<string>            $allowed
     * @param array<array-key, mixed> $actual
     */
    private function assertOnlyMembers(array $allowed, array $actual): void
    {
        $unexpected = \array_diff(\array_keys($actual), $allowed);
        self::assertSame([], \array_values($unexpected), 'Emitted an unexpected member: ' . \implode(', ', \array_map('strval', $unexpected)));
    }
}
