<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation required in a context must be sent on a create and may not be sent empty
 * where it is required, in the compiled request schema. An optional to-one may still be
 * cleared with `null`.
 */
#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerRequiredRelationTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>|null, bool, bool}>
     */
    public static function writes(): iterable
    {
        $owner = ['data' => ['type' => 'members', 'id' => '1']];
        $watchers = ['data' => [['type' => 'members', 'id' => '1']]];

        yield 'a create sending every required relationship' => [['owner' => $owner, 'watchers' => $watchers], true, true];
        yield 'a create with no relationships member' => [null, true, false];
        yield 'a create with an empty relationships member' => [[], true, false];
        yield 'a create leaving out the owner' => [['watchers' => $watchers], true, false];
        yield 'a create with a null owner' => [['owner' => ['data' => null], 'watchers' => $watchers], true, false];
        yield 'a create with no watchers' => [['owner' => $owner, 'watchers' => ['data' => []]], true, false];
        yield 'a create clearing an optional to-one' => [['owner' => $owner, 'watchers' => $watchers, 'reviewer' => ['data' => null]], true, true];
        yield 'an update with no relationships member' => [null, false, true];
        yield 'an update with a null owner' => [['owner' => ['data' => null]], false, false];
        yield 'an update with no watchers' => [['watchers' => ['data' => []]], false, true];
        yield 'an update clearing an optional to-one' => [['reviewer' => ['data' => null]], false, true];
    }

    /**
     * @param array<string, mixed>|null $relationships
     */
    #[Test]
    #[DataProvider('writes')]
    public function theRequiredRelationshipsAreEnforcedInTheirContext(?array $relationships, bool $creating, bool $valid): void
    {
        $data = ['type' => 'tasks'];
        if (!$creating) {
            $data['id'] = '1';
        }
        if ($relationships !== null) {
            $data['relationships'] = $relationships === [] ? new \stdClass() : $relationships;
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
            public static string $type = 'tasks';

            public function fields(): array
            {
                return [
                    Id::make(),
                    BelongsTo::make('owner', 'members')->required(),
                    BelongsTo::make('reviewer', 'members'),
                    HasMany::make('watchers', 'members')->requiredOnCreate(),
                ];
            }
        };
    }
}
