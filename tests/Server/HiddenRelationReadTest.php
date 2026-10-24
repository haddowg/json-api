<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A relation hidden from the caller answers its related and relationship reads with
 * the `404` an undeclared relation gets, once the handler has loaded the parent and
 * called {@see Server::refuseHiddenRelationRead()}.
 */
#[CoversClass(Server::class)]
final class HiddenRelationReadTest extends TestCase
{
    #[Test]
    public function aRelationHiddenFromTheCallerHasNoRelatedEndpoint(): void
    {
        $guest = $this->send(new Target('hr-articles', '1', 'owner'), 'guest');
        self::assertSame(404, $guest->getStatusCode());
        self::assertSame('RELATIONSHIP_NOT_EXISTS', $this->errorCode($guest));

        self::assertSame(200, $this->send(new Target('hr-articles', '1', 'owner'), 'admin')->getStatusCode());
    }

    #[Test]
    public function aRelationHiddenFromTheCallerHasNoRelationshipEndpoint(): void
    {
        foreach (['owner', 'watchers'] as $relationship) {
            $guest = $this->send(new Target('hr-articles', '1', $relationship, true), 'guest');
            self::assertSame(404, $guest->getStatusCode(), $relationship);
            self::assertSame('RELATIONSHIP_NOT_EXISTS', $this->errorCode($guest), $relationship);

            self::assertSame(200, $this->send(new Target('hr-articles', '1', $relationship, true), 'admin')->getStatusCode(), $relationship);
        }
    }

    #[Test]
    public function thePredicateSeesTheLoadedParent(): void
    {
        // Article 2 is public, so its owner is visible to a guest.
        self::assertSame(200, $this->send(new Target('hr-articles', '2', 'owner'), 'guest')->getStatusCode());
    }

    #[Test]
    public function aStaticallyHiddenRelationIsRefusedToo(): void
    {
        $this->expectException(RelationshipNotExists::class);

        $this->server()->refuseHiddenRelationRead(new Target('hr-articles', '1', 'internal'), HiddenRelationStore::article('1'), $this->request('admin'));
    }

    #[Test]
    public function aVisibleOrUnknownRelationOrTypeIsLeftAlone(): void
    {
        $server = $this->server();
        $request = $this->request('guest');
        $article = HiddenRelationStore::article('1');

        $server->refuseHiddenRelationRead(new Target('hr-articles', '1', 'author'), $article, $request);
        $server->refuseHiddenRelationRead(new Target('hr-articles', '1', 'nope'), $article, $request);
        $server->refuseHiddenRelationRead(new Target('hr-articles', '1'), $article, $request);
        $server->refuseHiddenRelationRead(new Target('unregistered', '1', 'owner'), $article, $request);

        $this->addToAssertionCount(1);
    }

    private function send(Target $target, string $role): ResponseInterface
    {
        $request = (new ServerRequest('GET', '/hr-articles/x', ['Accept' => 'application/vnd.api+json', 'X-Role' => $role]))
            ->withAttribute(Target::class, $target);
        $server = $this->server();

        return $server->withMiddleware([new ErrorHandlerMiddleware($server)])->handle($request);
    }

    private function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->withPsr17($psr17, $psr17)
            ->register(HrArticle::class)
            ->register(HrPerson::class)
            ->withHandler(new HiddenRelationHandler());
    }

    private function request(string $role): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/', ['X-Role' => $role]));
    }

    private function errorCode(ResponseInterface $response): mixed
    {
        /** @var array<string, mixed> $body */
        $body = \json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $errors = $body['errors'] ?? null;

        return \is_array($errors) && \is_array($errors[0] ?? null) ? $errors[0]['code'] ?? null : null;
    }
}

final class HiddenRelationStore
{
    /**
     * @return array<string, mixed>
     */
    public static function article(string $id): array
    {
        $person = ['id' => '3', 'name' => 'Ann'];

        return [
            'id' => $id,
            'title' => 'Hello',
            'public' => $id === '2',
            'author' => $person,
            'owner' => $person,
            'internal' => $person,
            'watchers' => [$person],
        ];
    }
}

final readonly class HiddenRelationHandler implements OperationHandlerInterface
{
    public function handle(JsonApiOperationInterface $operation): DataResponse|RelatedResponse|IdentifierResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);
        $http = $operation->context()->httpRequest();
        \assert($http !== null);
        $request = $http instanceof JsonApiRequestInterface ? $http : new JsonApiRequest($http);
        $target = $operation->target();
        $article = HiddenRelationStore::article((string) $target->id);
        $relationship = $target->relationship ?? '';

        $server->refuseHiddenRelationRead($target, $article, $request);

        if ($operation instanceof FetchRelatedOperation) {
            return RelatedResponse::fromResource($article[$relationship] ?? null, $server->serializerFor('hr-people'));
        }

        if ($operation instanceof FetchRelationshipOperation) {
            return IdentifierResponse::forRelationship($article, $server->serializerFor('hr-articles'), $relationship);
        }

        return DataResponse::fromResource($article, $server->serializerFor('hr-articles'));
    }
}

final class HrArticle extends AbstractResource
{
    public static string $type = 'hr-articles';

    public function fields(): array
    {
        $hiddenFromGuests = static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin'
            && !(\is_array($model) && ($model['public'] ?? false) === true);

        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('author', 'hr-people'),
            BelongsTo::make('owner', 'hr-people')->hidden($hiddenFromGuests),
            HasMany::make('watchers', 'hr-people')->hidden($hiddenFromGuests),
            BelongsTo::make('internal', 'hr-people')->hidden(),
        ];
    }
}

final class HrPerson extends AbstractResource
{
    public static string $type = 'hr-people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
