<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Negotiation\AcceptNegotiator;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcceptNegotiator::class)]
final class AcceptNegotiatorTest extends TestCase
{
    private const array AVAILABLE = ['application/vnd.api+json', 'text/csv', 'application/pdf'];

    #[Test]
    #[DataProvider('choices')]
    public function itPicksTheBestAvailableMediaType(string $accept, string $expected): void
    {
        self::assertSame($expected, AcceptNegotiator::negotiate($accept, self::AVAILABLE));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function choices(): array
    {
        return [
            'absent accepts anything, json:api by default' => ['', 'application/vnd.api+json'],
            'any' => ['*/*', 'application/vnd.api+json'],
            'exact raw' => ['text/csv', 'text/csv'],
            'type wildcard' => ['application/*', 'application/vnd.api+json'],
            'type wildcard without json:api' => ['text/*', 'text/csv'],
            'tie goes to json:api' => ['text/csv, application/vnd.api+json', 'application/vnd.api+json'],
            'q weights honoured' => ['application/vnd.api+json;q=0.5, text/csv', 'text/csv'],
            'most specific range sets the weight' => ['*/*;q=0.9, application/vnd.api+json;q=0.1', 'text/csv'],
            'q zero excludes' => ['application/vnd.api+json;q=0, */*;q=0.2', 'text/csv'],
            'equal weights keep declared order' => ['application/pdf, text/csv', 'text/csv'],
            'json:api ext parameter is fine' => ['application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"', 'application/vnd.api+json'],
            'a forbidden json:api parameter matches nothing' => ['application/vnd.api+json; charset=utf-8, application/pdf;q=0.1', 'application/pdf'],
            'case and parameters' => ['TEXT/CSV; header=present', 'text/csv'],
        ];
    }

    #[Test]
    public function nothingAcceptableIsA406(): void
    {
        self::assertNull(AcceptNegotiator::best('image/png', self::AVAILABLE));

        $this->expectException(MediaTypeUnacceptable::class);

        AcceptNegotiator::negotiate('image/png, text/*;q=0', ['application/pdf', 'text/csv']);
    }

    #[Test]
    public function aRefusalStillRendersAsJsonApi(): void
    {
        try {
            AcceptNegotiator::negotiate('image/png', self::AVAILABLE);
            self::fail('Expected a 406.');
        } catch (MediaTypeUnacceptable $e) {
            $response = ErrorResponse::fromException($e)->toPsrResponse(new StubServer(), new ServerRequest('GET', '/', ['Accept' => 'image/png']));
        }

        self::assertSame(406, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }
}
