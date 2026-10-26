<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AttributeWriteProhibited;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Server\ResourceRegistry;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A server that opts in with {@see Server::withReadOnlyAttributesRejected()} refuses a
 * write body sending an attribute the client cannot write, instead of ignoring it.
 */
#[CoversClass(Server::class)]
#[CoversClass(ResourceRegistry::class)]
#[CoversClass(AbstractResource::class)]
#[CoversClass(AttributeWriteProhibited::class)]
final class ReadOnlyAttributeRejectionTest extends TestCase
{
    #[Test]
    public function byDefaultAReadOnlyAttributeIsIgnored(): void
    {
        $article = RoStore::article();
        $server = $this->server(false);

        self::assertFalse($server->rejectsReadOnlyAttributes());
        $server->resourceFor('ro-articles')->hydrate($this->write('PATCH', ['title' => 'New', 'views' => 99]), $article);

        self::assertSame('New', $article->title);
        self::assertSame(1, $article->views);
    }

    #[Test]
    public function anOptedInServerRejectsEveryProhibitedAttributeAndWritesNothing(): void
    {
        $article = RoStore::article();

        try {
            $this->server()->resourceFor('ro-articles')->hydrate(
                $this->write('PATCH', ['summary' => 'x', 'title' => 'New', 'views' => 99, 'authorName' => 'Grace']),
                $article,
            );
            self::fail('Expected AttributeWriteProhibited');
        } catch (AttributeWriteProhibited $e) {
            self::assertSame(['views', 'summary', 'authorName'], $e->attributes);
            self::assertSame(403, $e->getStatusCode());
        }

        self::assertSame('Old', $article->title);
        self::assertSame('Ada', $article->author->name);
    }

    #[Test]
    public function eachProhibitedAttributeIsItsOwnErrorPointingAtTheMember(): void
    {
        $errors = (new AttributeWriteProhibited(['views', 'summary']))->getErrors();

        self::assertCount(2, $errors);
        $first = $errors[0]->transform();
        self::assertSame('403', $first['status']);
        self::assertSame('ATTRIBUTE_WRITE_PROHIBITED', $first['code']);
        self::assertSame(['pointer' => '/data/attributes/views'], $first['source']);
        self::assertSame(['pointer' => '/data/attributes/summary'], $errors[1]->transform()['source']);
    }

    #[Test]
    public function writabilityIsJudgedForTheVerb(): void
    {
        $resource = $this->server()->resourceFor('ro-articles');

        $created = $resource->hydrate($this->write('POST', ['title' => 'New', 'code' => 'A1']), RoStore::article());
        self::assertInstanceOf(\stdClass::class, $created);
        self::assertSame('A1', $created->code);

        $this->expectException(AttributeWriteProhibited::class);
        $resource->hydrate($this->write('PATCH', ['code' => 'B2']), RoStore::article());
    }

    #[Test]
    public function aReadOnlyPredicateIsJudgedForTheRequest(): void
    {
        $resource = $this->server()->resourceFor('ro-articles');

        $article = RoStore::article();
        $resource->hydrate($this->write('PATCH', ['status' => 'live'], admin: true), $article);
        self::assertSame('live', $article->status);

        $this->expectException(AttributeWriteProhibited::class);
        $resource->hydrate($this->write('PATCH', ['status' => 'live']), RoStore::article());
    }

    #[Test]
    public function aBodyOfWritableAttributesPasses(): void
    {
        $article = RoStore::article();
        $this->server()->resourceFor('ro-articles')->hydrate($this->write('PATCH', ['title' => 'New']), $article);

        self::assertSame('New', $article->title);
    }

    #[Test]
    public function optingInLeavesTheOriginalServerAlone(): void
    {
        $base = $this->server(false);
        $base->withReadOnlyAttributesRejected();

        $article = RoStore::article();
        $base->resourceFor('ro-articles')->hydrate($this->write('PATCH', ['views' => 99]), $article);

        self::assertSame(1, $article->views);
    }

    private function server(bool $reject = true): Server
    {
        return Server::make()
            ->withReadOnlyAttributesRejected($reject)
            ->register(RoArticle::class)
            ->register(RoPerson::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function write(string $method, array $attributes, bool $admin = false): JsonApiRequest
    {
        $data = ['type' => 'ro-articles', 'attributes' => $attributes];
        if ($method === 'PATCH') {
            $data['id'] = '1';
        }

        return new JsonApiRequest(
            (new ServerRequest($method, '/ro-articles', [
                'Content-Type' => 'application/vnd.api+json',
                'X-Role' => $admin ? 'admin' : 'guest',
            ]))->withParsedBody(['data' => $data]),
        );
    }
}

final class RoStore
{
    public static function article(): \stdClass
    {
        return (object) [
            'id' => '1',
            'title' => 'Old',
            'views' => 1,
            'code' => null,
            'status' => 'draft',
            'author' => (object) ['id' => '2', 'name' => 'Ada'],
        ];
    }
}

final class RoArticle extends AbstractResource
{
    public static string $type = 'ro-articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('views')->readOnly(),
            Str::make('summary')->computedUsing(static fn(mixed $model): string => 'computed'),
            Str::make('code')->readOnlyOnUpdate(),
            Str::make('status')->readOnly(
                static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
            Str::make('authorName')->on('author')->storedAs('name')->readOnly(),
            BelongsTo::make('author', 'ro-people'),
        ];
    }
}

final class RoPerson extends AbstractResource
{
    public static string $type = 'ro-people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
