<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A generated document keeps the fraction of every whole float, as a response does.
 */
#[CoversClass(OpenApi::class)]
final class DocumentNumberEncodingTest extends TestCase
{
    #[Test]
    public function aWholeDecimalExampleAndFloatBoundsKeepTheirFraction(): void
    {
        foreach ([false, true] as $pretty) {
            $json = $this->document()->toJsonString($pretty);
            $compact = \str_replace([' ', "\n"], '', $json);

            self::assertStringContainsString('"price":{"type":"number","maximum":100.0,"exclusiveMinimum":0.0,"examples":[5.0]}', $compact);
            self::assertStringContainsString('"stock":{"type":"integer","minimum":0,"examples":[5]}', $compact);
        }
    }

    private function document(): OpenApi
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(type: 'products', fields: [
                Id::make()->build(),
                Decimal::make('price')->exclusiveMin(0.0)->max(100.0)->example(5.0)->build(),
                Integer::make('stock')->min(0)->example(5)->build(),
            ])],
        ));
    }
}
