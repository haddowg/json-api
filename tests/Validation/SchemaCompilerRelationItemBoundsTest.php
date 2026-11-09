<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A to-many relation's `minItems()` / `maxItems()` bound its linkage in the compiled
 * request schema, in the context each applies to.
 */
#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerRelationItemBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, bool, bool}>
     */
    public static function writes(): iterable
    {
        yield 'too few on create' => ['tags', 0, true, false];
        yield 'enough on create' => ['tags', 1, true, true];
        yield 'too many on update' => ['tags', 3, false, false];
        yield 'a create-only bound on update' => ['editors', 0, false, true];
        yield 'a create-only bound on create' => ['editors', 0, true, false];
    }

    #[Test]
    #[DataProvider('writes')]
    public function theLinkageIsBoundedInItsContext(string $relation, int $members, bool $creating, bool $valid): void
    {
        $data = ['type' => 'articles', 'relationships' => [$relation => ['data' => \array_fill(0, $members, ['type' => 'tags', 'id' => '1'])]]];
        if (!$creating) {
            $data['id'] = '1';
        }

        if (!$valid) {
            $this->expectException(RequestBodyInvalidJsonApi::class);
        }
        (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest(['data' => $data], [(new SchemaCompiler())->compile($this->resource(), $creating)]);
        $this->addToAssertionCount(1);
    }

    private function resource(): AbstractResource
    {
        return new class extends AbstractResource {
            public static string $type = 'articles';

            public function fields(): array
            {
                return [
                    Id::make(),
                    HasMany::make('tags', 'tags')->minItems(1)->maxItems(2),
                    HasMany::make('editors', 'tags')->onCreate(static function (HasManyBuilder $b): void {
                        $b->minItems(1);
                    }),
                ];
            }
        };
    }
}
