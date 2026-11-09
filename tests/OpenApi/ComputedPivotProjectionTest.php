<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A computed pivot field renders on every response but is never written, so it is in the
 * response pivot shape and out of the request one, whether or not it was declared
 * read-only.
 */
#[CoversClass(OpenApiProjector::class)]
final class ComputedPivotProjectionTest extends TestCase
{
    #[Test]
    public function aComputedPivotFieldIsReadButNeverWritten(): void
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata(
            title: 'Catalog',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'playlists',
                    fields: [Id::make()->example('pl-1')->build()],
                    relations: [FakeRelationMetadata::toMany('tracks', ['tracks'], pivotFields: [
                        Integer::make('position')->example(1)->build(),
                        Str::make('label')->extractUsing(static fn(): string => 'x')->example('x')->build(),
                        Str::make('origin')->computed()->example('y')->build(),
                    ])],
                ),
                FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make()->example('tr-1')->build()]),
            ],
        ))->toArray();

        $schemas = $this->arrAt($document, 'components', 'schemas');
        self::assertSame(['position', 'label', 'origin'], \array_keys($this->arrAt($schemas, 'PlaylistsTracksPivot', 'properties')));
        self::assertSame(['position'], \array_keys($this->arrAt($schemas, 'PlaylistsTracksWritePivot', 'properties')));

        self::assertSame(
            [['type' => 'tracks', 'id' => 'tr-1', 'meta' => ['pivot' => ['position' => 1]]]],
            $this->arrAt($document, 'paths', '/playlists/{id}/relationships/tracks', 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'example', 'data'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
