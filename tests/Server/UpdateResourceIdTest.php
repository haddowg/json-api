<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Atomic\AtomicLoop;
use haddowg\JsonApi\Atomic\AtomicLoopBackendInterface;
use haddowg\JsonApi\Atomic\AtomicOperationsParser;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdMissing;
use haddowg\JsonApi\Hydrator\UpdateHydratorTrait;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Testing\SpecCompliance;
use haddowg\JsonApi\Tests\Double\StubServer;
use haddowg\JsonApi\Tests\Hydrator\Double\StubUpdateHydrator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * An update body's resource object must carry the id of the resource it changes. A
 * resource refuses one without, exactly as the update hydrator trait does.
 */
#[CoversClass(AbstractResource::class)]
#[CoversClass(UpdateHydratorTrait::class)]
#[Group('spec:crud')]
final class UpdateResourceIdTest extends TestCase
{
    #[Test]
    public function anUpdateWithNoIdIsRefused(): void
    {
        $response = $this->patch(['data' => ['type' => 'notes', 'attributes' => ['body' => 'changed']]]);

        self::assertSame(400, $response->getStatusCode());
        $document = $this->decode($response);
        self::assertSame([[
            'status' => '400',
            'code' => 'RESOURCE_ID_MISSING',
            'title' => 'Resource ID is missing',
            'detail' => 'A resource ID must be included in the document!',
            'source' => ['pointer' => '/data'],
        ]], self::at($document, 'errors'));
    }

    #[Test]
    public function aNullOrEmptyIdIsMissingToo(): void
    {
        foreach ([null, ''] as $id) {
            $response = $this->patch(['data' => ['type' => 'notes', 'id' => $id, 'attributes' => ['body' => 'changed']]]);

            self::assertSame(400, $response->getStatusCode());
            self::assertSame('RESOURCE_ID_MISSING', self::at($this->decode($response), 'errors', '0', 'code'));
        }
    }

    #[Test]
    public function aNonStringIdIsInvalid(): void
    {
        $response = $this->patch(['data' => ['type' => 'notes', 'id' => 7, 'attributes' => ['body' => 'changed']]]);

        self::assertSame(400, $response->getStatusCode());
        $error = self::at($this->decode($response), 'errors', '0');
        self::assertSame('RESOURCE_ID_INVALID', self::at($error, 'code'));
        self::assertSame(['pointer' => '/data/id'], self::at($error, 'source'));
    }

    #[Test]
    public function anUpdateNamingItsIdIsHydrated(): void
    {
        foreach (['7', '0'] as $id) {
            $response = $this->patch(['data' => ['type' => 'notes', 'id' => $id, 'attributes' => ['body' => 'changed']]], $id);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('changed', self::at($this->decode($response), 'data', 'attributes', 'body'));
        }
    }

    /**
     * @param array<string, mixed> $resource
     */
    #[Test]
    #[DataProvider('refusedResources')]
    public function aResourceRefusesAsTheHydratorTraitDoes(array $resource): void
    {
        $request = new JsonApiRequest(new ServerRequest('PATCH', '/notes/7', [], \json_encode(['data' => $resource], \JSON_THROW_ON_ERROR)));
        $traitResource = ['type' => 'user'] + $resource;
        $traitRequest = new JsonApiRequest(new ServerRequest('PATCH', '/user/7', [], \json_encode(['data' => $traitResource], \JSON_THROW_ON_ERROR)));

        $fromResource = $this->thrown(static fn(): mixed => (new UpdateIdNoteResource())->hydrate($request, (object) ['id' => '7', 'body' => '']));
        $fromTrait = $this->thrown(static fn(): mixed => (new StubUpdateHydrator())->hydrateForUpdate($traitRequest, []));

        self::assertSame($fromTrait::class, $fromResource::class);
        self::assertSame($fromTrait->getStatusCode(), $fromResource->getStatusCode());
        self::assertEquals($fromTrait->getErrors(), $fromResource->getErrors());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedResources(): iterable
    {
        yield 'no id' => [['type' => 'notes']];
        yield 'null id' => [['type' => 'notes', 'id' => null]];
        yield 'empty id' => [['type' => 'notes', 'id' => '']];
        yield 'integer id' => [['type' => 'notes', 'id' => 7]];
    }

    #[Test]
    #[Group('spec:extensions-and-profiles')]
    public function anAtomicUpdateWithNoIdIsRefusedAtItsOperation(): void
    {
        $operations = (new AtomicOperationsParser())->parse(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'notes', 'id' => '7'], 'data' => ['type' => 'notes', 'id' => '7', 'attributes' => ['body' => 'first']]],
            ['op' => 'update', 'ref' => ['type' => 'notes', 'id' => '7'], 'data' => ['type' => 'notes', 'attributes' => ['body' => 'second']]],
        ]]);
        $backend = new UpdateIdAtomicBackend();

        $response = (new AtomicLoop())->run($operations, $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);
        self::assertTrue($backend->rolledBack);
        $psr = $response->toPsrResponse(new StubServer(), new JsonApiRequest(new ServerRequest('POST', '/operations')));
        self::assertSame(400, $psr->getStatusCode());
        $document = $this->decode($psr);
        self::assertSame('RESOURCE_ID_MISSING', self::at($document, 'errors', '0', 'code'));
        self::assertSame(['pointer' => '/atomic:operations/1/data'], self::at($document, 'errors', '0', 'source'));
    }

    private function thrown(\Closure $hydrate): ResourceIdMissing|ResourceIdInvalid
    {
        try {
            $hydrate();
        } catch (ResourceIdMissing|ResourceIdInvalid $e) {
            return $e;
        }

        self::fail('Expected the update to be refused.');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(array $body, string $id = '7'): ResponseInterface
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()
            ->withBaseUri('https://api.test')
            ->register(UpdateIdNoteResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler(new class implements OperationHandlerInterface {
                public function handle(JsonApiOperationInterface $operation): DataResponse
                {
                    $server = $operation->context()->server;
                    if (!$operation instanceof UpdateResourceOperation || !$server instanceof Server) {
                        throw new \LogicException('Only an update is served.');
                    }

                    $resource = $server->resourceFor('notes');
                    $note = $resource->hydrate($operation->body(), (object) ['id' => $operation->target()->id, 'body' => 'original']);

                    return DataResponse::fromResource($note, $resource);
                }
            });

        $request = (new ServerRequest('PATCH', '/notes/' . $id, ['Content-Type' => 'application/vnd.api+json'], \json_encode($body, \JSON_THROW_ON_ERROR)))
            ->withAttribute(Target::class, new Target('notes', $id));

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        SpecCompliance::assert($body);

        $document = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}

final class UpdateIdNoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [Id::make(), Str::make('body')];
    }
}

/**
 * Hydrates each atomic update through the resource, as an adapter's backend does.
 */
final class UpdateIdAtomicBackend implements AtomicLoopBackendInterface
{
    public bool $rolledBack = false;

    public function begin(): void {}

    public function commit(): void {}

    public function rollback(): void
    {
        $this->rolledBack = true;
    }

    /**
     * @throws JsonApiExceptionInterface
     */
    public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult
    {
        $request = new JsonApiRequest(new ServerRequest('PATCH', '/notes/7', [], \json_encode(['data' => $op->data], \JSON_THROW_ON_ERROR)));
        (new UpdateIdNoteResource())->hydrate($request, (object) ['id' => '7', 'body' => '']);

        return AtomicResult::empty();
    }
}
