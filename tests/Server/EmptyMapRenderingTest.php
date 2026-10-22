<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Object-typed members rendered through {@see Server::handle()}: an empty map field
 * encodes as `{}` (not `[]`), and an empty links container or error source is left
 * out. Assertions read the encoded body, since decoding to PHP arrays cannot tell
 * `{}` from `[]`.
 */
#[CoversClass(ArrayHash::class)]
#[CoversClass(Map::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(JsonObject::class)]
#[CoversClass(Error::class)]
final class EmptyMapRenderingTest extends TestCase
{
    #[Test]
    public function anEmptyMapRendersAsAnObject(): void
    {
        $attributes = $this->fetch([
            'preferences' => [],
            'settings' => [],
            'shape' => [],
            'variant' => [],
        ]);

        self::assertStringContainsString('"preferences":{}', $attributes);
        self::assertStringContainsString('"address":{}', $attributes);
        self::assertStringContainsString('"shape":{}', $attributes);
        // An Obj whose children render keeps its keys.
        self::assertStringContainsString('"settings":{"label":null}', $attributes);
        // An unknown discriminator renders the stored value as is, still as an object.
        self::assertStringContainsString('"variant":{}', $attributes);
    }

    #[Test]
    public function aNullMapStaysNull(): void
    {
        $attributes = $this->fetch(['preferences' => null, 'settings' => null, 'shape' => null, 'variant' => null]);

        self::assertStringContainsString('"preferences":null', $attributes);
        self::assertStringContainsString('"settings":null', $attributes);
        self::assertStringContainsString('"shape":null', $attributes);
        self::assertStringContainsString('"variant":null', $attributes);
    }

    #[Test]
    public function aNonEmptyMapIsUnchanged(): void
    {
        $attributes = $this->fetch([
            'preferences' => ['theme' => 'dark', 'pageSize' => 20],
            'settings' => ['label' => 'home'],
            'shape' => ['label' => 'box'],
            'variant' => ['type' => 'circle', 'radius' => '2'],
        ]);

        self::assertStringContainsString('"preferences":{"theme":"dark","pageSize":20}', $attributes);
        self::assertStringContainsString('"settings":{"label":"home"}', $attributes);
        self::assertStringContainsString('"variant":{"type":"circle","radius":"2"}', $attributes);
    }

    #[Test]
    public function aListShapedMapRendersAsAnObject(): void
    {
        $attributes = $this->fetch(['preferences' => ['dark', 'compact']]);

        self::assertStringContainsString('"preferences":{"0":"dark","1":"compact"}', $attributes);
    }

    #[Test]
    public function anEmptyResourceLinksContainerIsOmitted(): void
    {
        $body = $this->body(['preferences' => []]);

        self::assertStringNotContainsString('"links":[]', $body);
        self::assertStringStartsWith('{"data":{"type":"accounts","id":"1","attributes":', $body);
    }

    #[Test]
    public function anEmptyErrorSourceAndLinksAreOmittedAndAnEmptyErrorIsAnObject(): void
    {
        $psr17 = new Psr17Factory();
        $server = Server::make()->withPsr17($psr17, $psr17);

        $body = (string) ErrorResponse::fromErrors(
            new Error(status: '400', source: new ErrorSource('', ''), links: new ErrorLinks()),
            new Error(),
        )->toPsrResponse($server, new ServerRequest('GET', '/accounts'))->getBody();

        self::assertSame('{"errors":[{"status":"400"},{}],"jsonapi":{"version":"1.1"}}', $body);
    }

    #[Test]
    public function anEmptyObjectInARequestHydratesToAnEmptyArray(): void
    {
        $request = new JsonApiRequest(
            (new ServerRequest('PATCH', '/accounts/1', ['Content-Type' => 'application/vnd.api+json']))
                ->withBody((new Psr17Factory())->createStream(
                    '{"data":{"type":"accounts","id":"1","attributes":{"preferences":{}}}}',
                )),
        );

        $model = (new EmptyMapAccountResource())->hydrate($request, ['preferences' => ['theme' => 'dark']]);

        self::assertIsArray($model);
        self::assertSame([], $model['preferences']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function fetch(array $values): string
    {
        $body = $this->body($values);

        $start = \strpos($body, '"attributes":');
        self::assertNotFalse($start, $body);

        return \substr($body, $start);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function body(array $values): string
    {
        $psr17 = new Psr17Factory();

        $handler = new class ($values) implements OperationHandlerInterface {
            /**
             * @param array<string, mixed> $values
             */
            public function __construct(private array $values) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                $account = (object) ['id' => '1', ...$this->values];

                return DataResponse::fromResource($account, $operation->context()->server->serializerFor('accounts'));
            }
        };

        $response = Server::make()
            ->register(EmptyMapAccountResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler)
            ->handle((new ServerRequest('GET', '/accounts/1'))->withAttribute(Target::class, new Target('accounts', '1')));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }
}

final class EmptyMapAccountResource extends AbstractResource
{
    public static string $type = 'accounts';

    public function fields(): array
    {
        return [
            Id::make(),
            ArrayHash::make('preferences')->nullable(),
            // A Map spreads its children over the model; with every child write-only
            // nothing renders into it.
            Map::make('address')->fields(Str::make('postcode')->writeOnly()),
            Obj::make('settings')->nullable()->fields(Str::make('label')),
            Obj::make('shape')->nullable()->fields(Str::make('secret')->writeOnly()),
            OneOf::make('variant')->nullable()->variant('circle', Str::make('radius')),
        ];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ResourceLinks
    {
        return new ResourceLinks();
    }

    public function emitsSelfLink(): bool
    {
        return false;
    }
}
