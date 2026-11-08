<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A custom action declaring {@see ActionCreated}, dispatched through
 * {@see Server::dispatch()}: its resource document renders `201` unless the action also
 * declares a `200` {@see ActionResource} or the handler chose a status itself.
 */
#[CoversClass(Server::class)]
#[CoversClass(ActionResponses::class)]
#[CoversClass(AbstractResponse::class)]
final class ActionCreatedTest extends TestCase
{
    #[Test]
    public function anActionDeclaringOnlyACreatedDocumentAnswers201(): void
    {
        self::assertSame(201, $this->sentStatus([new ActionCreated('orders')]));
    }

    #[Test]
    public function aCreatedDocumentBesideOtherStatusesStillAnswers201(): void
    {
        self::assertSame(201, $this->sentStatus([new ActionCreated('orders'), new MetaResult(), new NoContent()]));
    }

    #[Test]
    public function aHandlersOwnStatusWins(): void
    {
        self::assertSame(200, $this->sentStatus([new ActionCreated('orders')], static fn(DataResponse $response): DataResponse => $response->withStatus(200)));
    }

    #[Test]
    public function anActionDeclaringBothDocumentsAnswers200UnlessTheHandlerPicks201(): void
    {
        $responds = [new ActionResource('orders'), new ActionCreated('orders')];

        self::assertSame(200, $this->sentStatus($responds));
        self::assertSame(201, $this->sentStatus($responds, static fn(DataResponse $response): DataResponse => $response->withStatus(201)));
    }

    /**
     * @return iterable<string, array{list<ActionResponse>}>
     */
    public static function undeclaredCreation(): iterable
    {
        yield 'a 200 resource document' => [[new ActionResource('orders')]];
        yield 'no declared responses' => [[]];
    }

    /**
     * @param list<ActionResponse> $responds
     */
    #[Test]
    #[DataProvider('undeclaredCreation')]
    public function anyOtherActionKeepsThe200(array $responds): void
    {
        self::assertSame(200, $this->sentStatus($responds));
    }

    #[Test]
    public function onlyADataResponseTakesTheCreatedStatus(): void
    {
        $server = $this->server();
        $request = $this->request();
        $response = $server
            ->withHandler(new CreatedActionHandler(static fn(): MetaResponse => MetaResponse::fromMeta(['queued' => true])))
            ->dispatch($this->operation($server, $request, [new ActionCreated('orders'), new MetaResult()]))
            ->toPsrResponse($server, $request);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param list<ActionResponse>                         $responds
     * @param (\Closure(DataResponse): DataResponse)|null $decorate
     */
    private function sentStatus(array $responds, ?\Closure $decorate = null): int
    {
        $server = $this->server();
        $request = $this->request();
        $handler = new CreatedActionHandler(static function (Server $server) use ($decorate): DataResponse {
            $order = new \stdClass();
            $order->id = '2';
            $order->name = 'Copy';
            $response = DataResponse::fromResource($order, $server->serializerFor('orders'));

            return $decorate === null ? $response : $decorate($response);
        });

        $response = $server->withHandler($handler)->dispatch($this->operation($server, $request, $responds))->toPsrResponse($server, $request);
        $document = \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['type' => 'orders', 'id' => '2'], \array_intersect_key((array) ($document['data'] ?? []), ['type' => true, 'id' => true]));

        return $response->getStatusCode();
    }

    /**
     * @param list<ActionResponse> $responds
     */
    private function operation(Server $server, JsonApiRequest $request, array $responds): CustomActionOperation
    {
        return new CustomActionOperation(
            new Target('orders', '1'),
            QueryParameters::fromRequest($request),
            new OperationContext($server, $request),
            'duplicate',
            'POST',
            responds: $responds,
        );
    }

    private function request(): JsonApiRequest
    {
        return new JsonApiRequest((new ServerRequest('POST', 'https://api.example.com/orders/1/-actions/duplicate'))->withHeader('Accept', 'application/vnd.api+json'));
    }

    private function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()->withPsr17($psr17, $psr17)->register(ActionCreatedOrderResource::class);
    }
}

final readonly class CreatedActionHandler implements OperationHandlerInterface
{
    /**
     * @param \Closure(Server): (DataResponse|MetaResponse) $respond
     */
    public function __construct(private \Closure $respond) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|MetaResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        return ($this->respond)($server);
    }
}

class ActionCreatedOrderResource extends AbstractResource
{
    public static string $type = 'orders';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
