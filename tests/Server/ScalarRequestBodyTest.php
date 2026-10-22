<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\AbstractRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A request body that is well-formed JSON but not an object is not a JSON:API
 * document. Through the server it answers 400 `REQUIRED_TOP_LEVEL_MEMBERS_MISSING`,
 * whether the body-parsing middleware or the handler reads it first.
 */
#[CoversClass(AbstractRequest::class)]
final class ScalarRequestBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function scalarBodies(): iterable
    {
        yield 'string' => ['"x"'];
        yield 'padded string' => ['  "  x  "  '];
        yield 'number' => ['42'];
        yield 'true' => ['true'];
        yield 'null' => ['null'];
    }

    #[Test]
    #[DataProvider('scalarBodies')]
    public function theBodyParsingMiddlewareAnswers400(string $body): void
    {
        $this->assertRequiredTopLevelMembersMissing($this->post($body, parseBody: true));
    }

    #[Test]
    #[DataProvider('scalarBodies')]
    public function aHandlerReadingTheBodyAnswers400(string $body): void
    {
        $this->assertRequiredTopLevelMembersMissing($this->post($body, parseBody: false));
    }

    /**
     * @return array{int, mixed}
     */
    private function post(string $body, bool $parseBody): array
    {
        $psr17 = new Psr17Factory();

        $handler = new class implements OperationHandlerInterface {
            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                \assert($operation instanceof CreateResourceOperation);
                $resource = $operation->context()->server->serializerFor('notes');
                \assert($resource instanceof ScalarBodyNoteResource);

                $note = $resource->hydrate($operation->body(), new \stdClass());

                return DataResponse::fromResource($note, $resource);
            }
        };

        $base = Server::make()
            ->register(ScalarBodyNoteResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler);

        $middleware = [new ErrorHandlerMiddleware($base)];
        if ($parseBody) {
            $middleware[] = new RequestBodyParsingMiddleware();
        }

        $response = $base->withMiddleware($middleware)->handle(
            (new ServerRequest('POST', '/notes', ['Content-Type' => 'application/vnd.api+json']))
                ->withBody($psr17->createStream($body))
                ->withAttribute(Target::class, new Target('notes')),
        );

        return [$response->getStatusCode(), \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array{int, mixed} $result
     */
    private function assertRequiredTopLevelMembersMissing(array $result): void
    {
        [$status, $document] = $result;

        self::assertSame(400, $status, (string) \json_encode($document));
        self::assertIsArray($document);
        self::assertIsArray($document['errors'] ?? null);
        self::assertSame(['REQUIRED_TOP_LEVEL_MEMBERS_MISSING'], \array_column($document['errors'], 'code'));
    }
}

final class ScalarBodyNoteResource extends AbstractResource
{
    public static string $type = 'notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('body'),
        ];
    }
}
