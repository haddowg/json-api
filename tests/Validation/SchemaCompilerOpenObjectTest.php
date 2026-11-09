<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An open object compiles as the OpenAPI projection describes it: its undeclared members
 * are checked against the member field's schema and counted against its bounds.
 */
#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerOpenObjectTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validBodies(): iterable
    {
        yield 'rows of arbitrary headers' => [['rows' => [['Key' => 'RFP-123', 'Vendor' => 'Acme Media', 'CPL' => '42'], ['Key' => 'RFP-124']]]];
        yield 'an empty open object' => [['labels' => new \stdClass()]];
        // Hydration ignores a member it may not write, so an echoed one validates.
        yield 'echoed read-only members' => [['metrics' => ['views' => 3]]];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('validBodies')]
    public function anOpenObjectAcceptsUndeclaredMembers(array $attributes): void
    {
        foreach ([true, false] as $creating) {
            $this->validate($attributes, $creating);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'a member too long' => [['rows' => [['Key' => 'a', 'Vendor' => \str_repeat('x', 201)]]]];
        yield 'a member of the wrong type' => [['rows' => [['Key' => 'a', 'CPL' => 42]]]];
        yield 'too many members' => [['rows' => [['Key' => 'a', 'b' => '1', 'c' => '2', 'd' => '3']]]];
        yield 'a declared child of the wrong type' => [['rows' => [['Key' => 1]]]];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function anOpenObjectRefusesWhatItsMembersDoNotAllow(array $attributes): void
    {
        $this->expectException(RequestBodyInvalidJsonApi::class);
        $this->validate($attributes, true);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function validate(array $attributes, bool $creating): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'sheets';

            public function fields(): array
            {
                return [
                    Id::make(),
                    ArrayList::make('rows')->of(Obj::make('row')->fields(Str::make('Key'))->additionalProperties(Str::make('*')->maxLength(200))->maxProperties(3)),
                    Obj::make('labels')->additionalProperties(Str::make('*')),
                    Obj::make('metrics')->additionalProperties(Integer::make('*')->readOnly()),
                ];
            }
        };

        $data = ['type' => 'sheets', 'attributes' => $attributes];
        if (!$creating) {
            $data['id'] = '1';
        }
        $body = \json_decode(\json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
        (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest($body, [(new SchemaCompiler())->compile($resource, $creating)]);
    }
}
