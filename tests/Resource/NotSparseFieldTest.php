<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A field declared `notSparseField()` renders whatever `fields[type]` lists.
 */
#[Group('spec:sparse-fieldsets')]
final class NotSparseFieldTest extends TestCase
{
    #[Test]
    public function aNonSparseAttributeRendersWhenTheFieldsetLeavesItOut(): void
    {
        $data = $this->render(['fields' => ['notes' => 'title']]);

        self::assertSame(['title' => 'Hello', 'slug' => 'hello'], $data['attributes'] ?? null);
    }

    #[Test]
    public function aNonSparseRelationshipRendersWhenTheFieldsetLeavesItOut(): void
    {
        $data = $this->render(['fields' => ['notes' => 'title']]);

        self::assertIsArray($data['relationships'] ?? null);
        self::assertSame(['owner'], \array_keys($data['relationships']));
    }

    #[Test]
    public function anEmptyFieldsetRendersNoFieldsAtAll(): void
    {
        $data = $this->render(['fields' => ['notes' => '']]);

        self::assertArrayNotHasKey('attributes', $data);
        self::assertArrayNotHasKey('relationships', $data);
    }

    #[Test]
    public function withoutAFieldsetEveryFieldRenders(): void
    {
        $data = $this->render([]);

        self::assertSame(['title' => 'Hello', 'slug' => 'hello', 'body' => 'Text'], $data['attributes'] ?? null);
        self::assertIsArray($data['relationships'] ?? null);
        self::assertSame(['owner', 'editor'], \array_keys($data['relationships']));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function render(array $query): array
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()->withPsr17($psr17, $psr17)->register(NonSparseNoteResource::class)->register(NonSparsePersonResource::class);
        $request = new JsonApiRequest((new ServerRequest('GET', '/notes/1'))->withQueryParams($query));

        $response = DataResponse::fromResource(
            ['id' => '1', 'title' => 'Hello', 'slug' => 'hello', 'body' => 'Text', 'owner' => ['id' => '7'], 'editor' => ['id' => '8']],
            $server->serializerFor('notes'),
        )->toPsrResponse($server, $request);

        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        /** @var array<string, mixed> $data */
        $data = $body['data'];

        return $data;
    }
}

final class NonSparseNoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('slug')->notSparseField(),
            Str::make('body'),
            BelongsTo::make('owner', 'people')->notSparseField(),
            BelongsTo::make('editor', 'people'),
        ];
    }
}

final class NonSparsePersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [Id::make()];
    }
}
