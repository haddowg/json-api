<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AttributeMemberUnrecognized;
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\ArrayListBuilder;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ObjectValue;
use haddowg\JsonApi\Resource\ObjectValues;
use haddowg\JsonApi\Resource\UndeclaredAttributeMembers;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\SchemaCompiler;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `fillUsing()` or `deserializeUsing()` hook changes how a value is stored, not what is
 * valid: a write-hooked list or object projects, walks and refuses exactly as an unhooked
 * one does.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(ObjectValues::class)]
#[CoversClass(UndeclaredAttributeMembers::class)]
final class WriteHookedValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function hooks(): iterable
    {
        yield 'unhooked list' => [false];
        yield 'hooked list' => [true];
    }

    #[Test]
    #[DataProvider('hooks')]
    public function theRowsProjectTheirFullDeclaredItems(bool $hooked): void
    {
        $expected = [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string', 'maxLength' => 65535],
            'minProperties' => 1,
            'maxProperties' => 200,
        ];

        foreach ([RepresentationContext::Create, RepresentationContext::Update] as $context) {
            $schema = $this->project(self::rows($hooked)->build(), $context);

            self::assertSame(1000, $schema['maxItems'] ?? null, $context->name);
            $items = $schema['items'] ?? null;
            self::assertIsArray($items);
            unset($items['properties']);
            self::assertEquals($expected, $items, $context->name);
        }
    }

    #[Test]
    public function aWriteHookedObjProjectsItsDeclaredChildrenClosed(): void
    {
        $schema = $this->project(self::settings(), RepresentationContext::Create);

        self::assertSame(['theme'], \array_keys((array) ($schema['properties'] ?? [])));
        self::assertFalse($schema['additionalProperties'] ?? null);
        self::assertSame(2, $schema['maxProperties'] ?? null);
    }

    #[Test]
    #[DataProvider('hooks')]
    public function theWalkReachesEveryRowOfTheList(bool $hooked): void
    {
        $long = \str_repeat('x', 70000);
        $wide = [];
        for ($i = 0; $i < 201; ++$i) {
            $wide['c' . $i] = 'v';
        }

        $found = ObjectValues::in([self::rows($hooked)->build()], ['rows' => [[], ['cell' => $long], $wide]], true, new StubJsonApiRequest());

        self::assertSame(
            ['/data/attributes/rows/0', '/data/attributes/rows/1', '/data/attributes/rows/2'],
            \array_map(static fn(ObjectValue $value): string => $value->pointer, $found),
        );
        self::assertEquals([new MinProperties(1), new MaxProperties(200)], $found[0]->memberCounts(true));
        self::assertSame(['cell' => $long], $found[1]->additional);
        self::assertSame('/data/attributes/rows/1/cell', $found[1]->memberPointer('cell'));
        self::assertEquals([new MaxLength(65535)], $found[1]->memberField?->constraints());
        self::assertCount(201, $found[2]->additional);
    }

    #[Test]
    public function theWalkReachesTheChildrenOfAWriteHookedObj(): void
    {
        $settings = Obj::make('settings')
            ->fields(Obj::make('limits')->fields(Integer::make('max'))->maxProperties(1))
            ->fillUsing(static fn(mixed $model): mixed => $model)
            ->build();

        $found = ObjectValues::in([$settings], ['settings' => ['limits' => ['max' => 1]]], true, new StubJsonApiRequest());

        self::assertSame(['/data/attributes/settings/limits'], \array_map(static fn(ObjectValue $value): string => $value->pointer, $found));
    }

    /**
     * @return iterable<string, array{bool, array<string, mixed>, string}>
     */
    public static function invalidRows(): iterable
    {
        $wide = new \stdClass();
        for ($i = 0; $i < 201; ++$i) {
            $wide->{'c' . $i} = 'v';
        }
        $many = \array_fill(0, 1001, (object) ['cell' => 'v']);

        foreach ([false, true] as $hooked) {
            $label = $hooked ? 'hooked' : 'unhooked';
            yield $label . ' empty row' => [$hooked, ['rows' => [new \stdClass()]], '/data/attributes/rows/0'];
            yield $label . ' long cell' => [$hooked, ['rows' => [(object) ['cell' => \str_repeat('x', 70000)]]], '/data/attributes/rows/0/cell'];
            yield $label . ' too many members' => [$hooked, ['rows' => [$wide]], '/data/attributes/rows/0'];
            yield $label . ' too many rows' => [$hooked, ['rows' => $many], '/data/attributes/rows'];
        }
    }

    /**
     * The document the server publishes refuses each reported payload where the server
     * does, at the same pointer.
     *
     * @param array<string, mixed> $attributes
     */
    #[Test]
    #[DataProvider('invalidRows')]
    public function theProjectedSchemaRefusesEachInvalidRow(bool $hooked, array $attributes, string $pointer): void
    {
        $resource = $hooked ? new HookedSheet() : new Sheet();
        $body = \json_decode(\json_encode(['data' => ['type' => 'sheets', 'attributes' => $attributes]], \JSON_THROW_ON_ERROR));

        try {
            (new DocumentValidator(new VendoredSchemaProvider()))->validateRequest($body, [(new SchemaCompiler())->compile($resource, true)]);
            self::fail('Expected RequestBodyInvalidJsonApi');
        } catch (RequestBodyInvalidJsonApi $e) {
            $pointers = \array_map(static fn(Error $error): ?string => $error->source?->pointer, $e->getErrors());
            self::assertContains($pointer, $pointers);
        }
    }

    #[Test]
    public function aWriteHookedObjRefusesAnUndeclaredMemberBeforeTheHookRuns(): void
    {
        $request = new JsonApiRequest(
            (new ServerRequest('POST', '/sheets', ['Content-Type' => 'application/vnd.api+json']))
                ->withParsedBody(['data' => ['type' => 'sheets', 'attributes' => ['settings' => ['theme' => 'dark', 'font' => 'mono']]]]),
        );
        $model = (object) ['id' => null, 'rows' => [], 'settings' => null];

        try {
            Server::make()->register(HookedSheet::class)->resourceFor('sheets')->hydrate($request, $model);
            self::fail('Expected AttributeMemberUnrecognized');
        } catch (AttributeMemberUnrecognized $e) {
            self::assertSame(['/data/attributes/settings/font'], $e->pointers);
            self::assertSame(400, $e->getStatusCode());
        }
        self::assertNull($model->settings);
    }

    public static function rows(bool $hooked): ArrayListBuilder
    {
        $rows = ArrayList::make('rows')
            ->of(Obj::make('row')->additionalProperties(Str::make('cell')->maxLength(65535))->minProperties(1)->maxProperties(200))
            ->maxItems(1000);

        return $hooked ? $rows->fillUsing(static fn(mixed $model): mixed => $model) : $rows;
    }

    public static function settings(): FieldInterface
    {
        return Obj::make('settings')
            ->fields(Str::make('theme'))
            ->maxProperties(2)
            ->fillUsing(static function (mixed $model, mixed $value): mixed {
                \assert($model instanceof \stdClass);
                $model->settings = $value;

                return $model;
            })
            ->build();
    }

    /**
     * @return array<string, mixed>
     */
    private function project(FieldInterface $field, RepresentationContext $context): array
    {
        $properties = (new SchemaProjector())->projectAttributes([$field], $context)->toArray()['properties'] ?? null;
        self::assertIsArray($properties);
        $schema = $properties[$field->name()] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }
}

class Sheet extends AbstractResource
{
    public static string $type = 'sheets';

    protected bool $hooked = false;

    public function fields(): array
    {
        return [
            Id::make(),
            WriteHookedValuesTest::rows($this->hooked),
            WriteHookedValuesTest::settings(),
        ];
    }
}

final class HookedSheet extends Sheet
{
    protected bool $hooked = true;
}
