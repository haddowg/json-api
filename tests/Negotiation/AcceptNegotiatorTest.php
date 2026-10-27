<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Negotiation\AcceptNegotiator;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
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

    #[Test]
    public function anActionThatRedirectsNeverRefusesAnAccept(): void
    {
        $responds = [new SeeOther(mediaType: 'text/csv')];

        self::assertNull(AcceptNegotiator::negotiateAction('text/html', $responds));
        self::assertSame('application/vnd.api+json', AcceptNegotiator::negotiateAction('application/vnd.api+json', $responds));
        self::assertSame('application/vnd.api+json', AcceptNegotiator::negotiateAction('', $responds));
    }

    #[Test]
    public function anActionThatRedirectsStillOffersItsRawResults(): void
    {
        self::assertSame('text/csv', AcceptNegotiator::negotiateAction('text/csv', [new SeeOther(mediaType: 'text/csv'), new RawResult('text/csv')]));
    }

    /**
     * @return array<string, array{list<\haddowg\JsonApi\OpenApi\Metadata\ActionResponse>}>
     */
    public static function nonRedirectingDeclarations(): array
    {
        return [
            'a meta result' => [[new MetaResult()]],
            'a bare SeeOther' => [[new SeeOther()]],
            'a SeeOther with only a description' => [[new SeeOther('The job is done.')]],
        ];
    }

    /**
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\ActionResponse> $responds
     */
    #[Test]
    #[DataProvider('nonRedirectingDeclarations')]
    public function anActionThatDoesNotAnswerWithAUrlRefusesAnUnacceptableAccept(array $responds): void
    {
        $this->expectException(MediaTypeUnacceptable::class);

        AcceptNegotiator::negotiateAction('text/html', $responds);
    }

    #[Test]
    #[DataProvider('jsonApiPreferences')]
    public function itTellsWhetherARequestPrefersJsonApi(string $accept, bool $expected): void
    {
        self::assertSame($expected, AcceptNegotiator::prefersJsonApi($accept));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jsonApiPreferences(): array
    {
        return [
            'absent' => ['', false],
            'any' => ['*/*', false],
            'application wildcard' => ['application/*', false],
            'another type' => ['text/csv', false],
            'json:api' => ['application/vnd.api+json', true],
            'json:api with a profile' => ['application/vnd.api+json; profile="https://example.com/p"', true],
            'json:api with a forbidden parameter' => ['application/vnd.api+json; charset=utf-8', false],
            'json:api refused' => ['application/vnd.api+json;q=0', false],
            'tie with another type' => ['text/html, application/vnd.api+json', true],
            'tie with a wildcard' => ['application/vnd.api+json;q=0.5, */*;q=0.5', true],
            'outweighed by another type' => ['application/vnd.api+json;q=0.5, text/csv', false],
            'outweighed by a wildcard' => ['application/vnd.api+json;q=0.9, */*', false],
            'case-insensitive' => ['Application/VND.API+JSON', true],
        ];
    }
}
