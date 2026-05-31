<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class VendoredSchemaProviderTest extends TestCase
{
    #[Test]
    public function responseSchemaParsesAndCarriesItsId(): void
    {
        $schema = (new VendoredSchemaProvider())->responseSchema();

        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertObjectHasProperty('$id', $schema);
        self::assertSame('https://jsonapi.org/schemas/spec/v1.1/draft', $schema->{'$id'});
        self::assertSame('https://jsonapi.org/schemas/spec/v1.1/draft', (new VendoredSchemaProvider())->responseSchemaId());
    }

    #[Test]
    public function requestSchemaParsesAndCarriesItsId(): void
    {
        $schema = (new VendoredSchemaProvider())->requestSchema();

        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertObjectHasProperty('$id', $schema);
        self::assertSame('https://jsonapi.org/schemas/spec/v1.1/request', $schema->{'$id'});
        self::assertSame('https://jsonapi.org/schemas/spec/v1.1/request', (new VendoredSchemaProvider())->requestSchemaId());
    }

    #[Test]
    public function rootUnevaluatedPropertiesIsStrippedFromResponseSchema(): void
    {
        // Relocated onto the validator's allOf composite so profile fragments can
        // extend top-level members; the vendored file keeps it (faithful copy).
        $schema = (new VendoredSchemaProvider())->responseSchema();

        self::assertObjectNotHasProperty('unevaluatedProperties', $schema);
    }

    #[Test]
    public function vendoredFileRetainsRootUnevaluatedProperties(): void
    {
        $raw = \file_get_contents(\dirname(__DIR__, 2) . '/resources/schemas/jsonapi-1.1.json');
        self::assertIsString($raw);

        $decoded = \json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertObjectHasProperty('unevaluatedProperties', $decoded);
        self::assertFalse($decoded->unevaluatedProperties);
    }

    #[Test]
    public function schemasAreMemoizedPerInstance(): void
    {
        $provider = new VendoredSchemaProvider();

        self::assertSame($provider->responseSchema(), $provider->responseSchema());
        self::assertSame($provider->requestSchema(), $provider->requestSchema());
    }
}
