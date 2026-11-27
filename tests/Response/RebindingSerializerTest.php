<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An adapter re-renders a response it did not build, such as one a custom action returned,
 * through a serializer decorator that rebuilds some relations against another resolver.
 */
#[CoversClass(DataResponse::class)]
#[CoversClass(RendersRelationsTrait::class)]
final class RebindingSerializerTest extends TestCase
{
    #[Test]
    public function includedMembersRenderThroughTheRebuiltRelation(): void
    {
        $body = $this->render(['include' => 'owner']);

        self::assertIsArray($body['included'] ?? null);
        self::assertIsArray($body['included'][0]);
        self::assertSame(['via' => 'decorator'], $body['included'][0]['meta'] ?? null);
    }

    #[Test]
    public function aRebuiltNonSparseRelationStillRendersWhenTheFieldsetLeavesItOut(): void
    {
        $body = $this->render(['fields' => ['rebound-notes' => 'title']]);

        self::assertIsArray($body['data']);
        self::assertIsArray($body['data']['relationships'] ?? null);
        self::assertSame(['owner'], \array_keys($body['data']['relationships']));
    }

    #[Test]
    public function withoutAFieldsetEveryRebuiltRelationRenders(): void
    {
        $body = $this->render([]);

        self::assertIsArray($body['data']);
        self::assertIsArray($body['data']['relationships'] ?? null);
        self::assertSame(['owner', 'editor'], \array_keys($body['data']['relationships']));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function render(array $query): array
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()->withPsr17($psr17, $psr17)->register(ReboundNoteResource::class)->register(ReboundPersonResource::class);
        $request = new JsonApiRequest((new ServerRequest('POST', '/rebound-notes/1/-actions/publish'))->withQueryParams($query));

        $model = ['id' => '1', 'title' => 'Hello', 'owner' => ['id' => '7'], 'editor' => ['id' => '7']];
        $action = DataResponse::fromResource($model, $server->serializerFor('rebound-notes'))->withMeta(['published' => true]);

        $substitute = (new StubSerializerResolver('people'))
            ->withSerializer('people', new StubResource('people', '7', meta: ['via' => 'decorator']));

        $response = $action
            ->withSerializer(new RelationRebindingSerializer($action->serializer(), $substitute, ['owner', 'editor']))
            ->toPsrResponse($server, $request);

        /** @var array<string, mixed> $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['published' => true], $body['meta'] ?? null);

        return $body;
    }
}

/**
 * Delegates to the inner serializer and rebuilds the named relations against another resolver.
 */
final class RelationRebindingSerializer extends AbstractSerializer
{
    use RendersRelationsTrait;

    /**
     * @param list<string> $names
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly SerializerResolverInterface $resolver,
        private readonly array $names,
    ) {}

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);
        if (!$this->inner instanceof DeclaresRelationsInterface) {
            return $relationships;
        }

        foreach ($this->names as $name) {
            $relation = $this->inner->relationNamedIncludingHidden($name);
            if ($relation !== null && \array_key_exists($name, $relationships)) {
                $relationships[$name] = self::relationshipCallable($relation, $this->resolver);
            }
        }

        return $relationships;
    }
}

final class ReboundNoteResource extends AbstractResource
{
    public static string $type = 'rebound-notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('owner', 'people')->notSparseField(),
            BelongsTo::make('editor', 'people'),
        ];
    }
}

final class ReboundPersonResource extends AbstractResource
{
    public static string $type = 'people';

    public function fields(): array
    {
        return [Id::make()];
    }
}
