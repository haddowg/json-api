<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Validation;

use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A nested child read-only for the write is ignored rather than rejected, so a client
 * echoing a fetched value back still validates, though the OpenAPI write schema leaves the
 * child out.
 */
#[CoversClass(SchemaCompiler::class)]
final class SchemaCompilerReadOnlyChildTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function echoedBodies(): iterable
    {
        $echo = ['label' => 'a', 'created' => '2026-01-01'];

        yield 'an object' => [['settings' => $echo]];
        yield 'a map' => [['limits' => $echo]];
        yield 'a list element' => [['steps' => [$echo]]];
        yield 'a variant' => [['block' => ['kind' => 'note', ...$echo]]];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('echoedBodies')]
    public function anEchoedReadOnlyChildValidatesOnEveryWrite(array $attributes): void
    {
        foreach ([true, false] as $creating) {
            $this->validate($attributes, $creating);
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anUndeclaredChildIsStillRejected(): void
    {
        $this->expectException(RequestBodyInvalidJsonApi::class);
        $this->validate(['settings' => ['label' => 'a', 'colour' => 'red']], false);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function validate(array $attributes, bool $creating): void
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'widgets';

            public function fields(): array
            {
                $children = static fn(): array => [Str::make('label'), Str::make('created')->readOnly()];

                return [
                    Id::make(),
                    Obj::make('settings')->fields(...$children()),
                    Map::make('limits')->fields(...$children()),
                    ArrayList::make('steps')->of(Obj::make('step')->fields(...$children())),
                    OneOf::make('block')->discriminator('kind')->variant('note', ...$children()),
                ];
            }
        };

        $data = ['type' => 'widgets', 'attributes' => $attributes];
        if (!$creating) {
            $data['id'] = '1';
        }
        (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest(['data' => $data], [(new SchemaCompiler())->compile($resource, $creating)]);
    }
}
