<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\StandaloneResourceObject;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\IntegerBuilder;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\RenderedMeta;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pivot relation's member renders `meta.pivot` as an object even when the request hides
 * every pivot field, so the identifier still matches the projected schema, which requires
 * `pivot` and types it as an object.
 */
#[CoversClass(MorphToMany::class)]
#[CoversClass(MetaShape::class)]
#[CoversClass(RenderedMeta::class)]
final class EmptyPivotRenderingTest extends TestCase
{
    #[Test]
    public function anIdentifiedMorphToManyRendersAnEmptyPivotAsAnObject(): void
    {
        $body = $this->render(true);

        self::assertStringContainsString('"meta":{"pivot":{}}', $body);
        self::assertStringNotContainsString('"pivot":[]', $body);
    }

    #[Test]
    public function aVisiblePivotStillRendersItsValues(): void
    {
        self::assertStringContainsString('"meta":{"pivot":{"rank":3,"note":"lead"}}', $this->render(false));
    }

    #[Test]
    public function theEmptyPivotMatchesTheProjectedSchema(): void
    {
        $document = \json_decode($this->render(true), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        $metadata = new FakeServerMetadata('API', '1', [
            FakeTypeMetadata::resource('owners', [Id::make()->build()], relations: [new FakeRelationMetadata(
                'members',
                ['agencies'],
                true,
                pivotFields: EmptyPivotOwnerResource::pivotFields(),
                polymorphic: true,
                identifiesMembers: true,
            )]),
        ]);
        $schema = \json_decode(\json_encode(StandaloneResourceObject::project($metadata, 'owners', [Id::make()->build()], new SchemaProjector())->toArray(), \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);

        $validator = new Validator();
        $result = $validator->validate($document->data, $schema);
        self::assertTrue($result->isValid(), $result->error()?->message() ?? '');

        // An empty list in its place is not an object, so the schema refuses it.
        $listed = \json_decode(\str_replace('"pivot":{}', '"pivot":[]', $this->render(true)), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $listed);
        self::assertFalse($validator->validate($listed->data, $schema)->isValid());
    }

    #[Test]
    public function theRenderedMetaCheckDoesNotReportAHiddenRequiredFieldAsMissing(): void
    {
        $document = \json_decode($this->render(true), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $resource = new EmptyPivotOwnerResource();

        self::assertSame([], RenderedMeta::violations($document, static fn(string $type): ?object => $type === 'owners' ? $resource : null));
    }

    protected function tearDown(): void
    {
        EmptyPivotOwnerResource::$hideAll = false;
    }

    private function render(bool $hideAll): string
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->register(EmptyPivotOwnerResource::class)
            ->register(EmptyPivotAgencyResource::class)
            ->withPsr17($psr17, $psr17);
        EmptyPivotOwnerResource::$hideAll = $hideAll;
        $owner = (object) ['id' => '1', 'rows' => [(object) ['type' => 'agencies', 'key' => 7, 'rank' => 3, 'note' => 'lead']]];

        return (string) DataResponse::fromResource($owner, $server->serializerFor('owners'))
            ->toPsrResponse($server, new ServerRequest('GET', '/owners/1'))
            ->getBody();
    }
}

final class EmptyPivotOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public static bool $hideAll = false;

    public function fields(): array
    {
        return [
            Id::make(),
            MorphToMany::make('members', ['agencies'])
                ->identifiedBy(static fn(\stdClass $owner): array => \array_map(static fn(\stdClass $row): array => [$row->type, $row->key, $row], $owner->rows))
                ->fields(...self::pivotFieldBuilders())
                ->withData(),
        ];
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    public static function pivotFields(): array
    {
        return \array_map(static fn(IntegerBuilder|StrBuilder $builder): \haddowg\JsonApi\Resource\Field\FieldInterface => $builder->build(), self::pivotFieldBuilders());
    }

    /**
     * Both fields are hidden while `$hideAll` is set, so the request hides every one.
     *
     * @return list<IntegerBuilder|StrBuilder>
     */
    private static function pivotFieldBuilders(): array
    {
        return [
            Integer::make('rank')->required()->hidden(static fn(): bool => self::$hideAll),
            Str::make('note')->hidden(static fn(): bool => self::$hideAll),
        ];
    }
}

final class EmptyPivotAgencyResource extends AbstractResource
{
    public static string $type = 'agencies';

    public function fields(): array
    {
        return [Id::make()];
    }
}
