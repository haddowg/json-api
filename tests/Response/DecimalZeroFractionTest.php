<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A whole Decimal is sent with its fraction, so a client reads `5.0` and an Integer
 * beside it stays `5`.
 */
#[CoversClass(AbstractResponse::class)]
final class DecimalZeroFractionTest extends TestCase
{
    #[Test]
    public function aWholeDecimalKeepsItsFraction(): void
    {
        $body = $this->render($this->server());

        self::assertStringContainsString('"attributes":{"price":5.0,"stored":20.0,"rating":4.5,"quantity":5}', $body);
    }

    #[Test]
    public function encodeOptionsTheServerSetsDoNotDropIt(): void
    {
        $body = $this->render($this->server()->withEncodeOptions(\JSON_UNESCAPED_SLASHES));

        self::assertStringContainsString('"price":5.0', $body);
    }

    #[Test]
    public function encodeOptionsTheResponseSetsDoNotDropIt(): void
    {
        $server = $this->server()->register(DzfItemResource::class);
        $response = DataResponse::fromResource(new DzfItem(), $server->resourceFor('dzf-items'))->withEncodeOptions(0);

        $body = (string) $response->toPsrResponse($server, $this->request())->getBody();

        self::assertStringContainsString('"price":5.0', $body);
    }

    private function render(Server $server): string
    {
        $server = $server->register(DzfItemResource::class);

        return (string) DataResponse::fromResource(new DzfItem(), $server->resourceFor('dzf-items'))
            ->toPsrResponse($server, $this->request())
            ->getBody();
    }

    private function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()->withPsr17($psr17, $psr17);
    }

    private function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/dzf-items/1'));
    }
}

final class DzfItem
{
    public string $id = '1';

    public float $price = 5.0;

    public string $stored = '20.00';

    public float $rating = 4.5;

    public int $quantity = 5;
}

final class DzfItemResource extends AbstractResource
{
    public static string $type = 'dzf-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Decimal::make('price'),
            Decimal::make('stored'),
            Decimal::make('rating'),
            Integer::make('quantity'),
        ];
    }
}
