<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Exception\AuthenticationRequired;
use haddowg\JsonApi\Response\ErrorRenderer;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorRenderer::class)]
#[Group('spec:errors')]
final class ErrorRendererTest extends TestCase
{
    #[Test]
    public function aSingleErrorRendersItsMembersWithItsContextFilledIn(): void
    {
        $error = new Error(
            status: '409',
            code: 'CONFLICT',
            title: 'Conflict',
            detail: 'Type {type} is not accepted here',
            source: ErrorSource::fromPointer('/data/type'),
            context: ['type' => 'people'],
        );

        self::assertSame(
            [
                'status' => '409',
                'code' => 'CONFLICT',
                'title' => 'Conflict',
                'detail' => 'Type people is not accepted here',
                'source' => ['pointer' => '/data/type'],
            ],
            ErrorRenderer::error($error),
        );
    }

    #[Test]
    public function aResolverReplacesTheCopyByCode(): void
    {
        $resolver = new class implements ErrorMessageResolverInterface {
            public function title(string $code): ?string
            {
                return $code === 'CONFLICT' ? 'Konflikt' : null;
            }

            public function detail(string $code): ?string
            {
                return null;
            }
        };

        $rendered = ErrorRenderer::error(new Error(code: 'CONFLICT', title: 'Conflict', detail: 'As written'), $resolver);

        self::assertSame('Konflikt', $rendered['title']);
        self::assertSame('As written', $rendered['detail']);
    }

    #[Test]
    public function aDocumentLeavesTheJsonapiMemberOutUnlessGiven(): void
    {
        $error = new Error(status: '403', title: 'Forbidden');

        self::assertSame(['errors' => [['status' => '403', 'title' => 'Forbidden']]], ErrorRenderer::document([$error]));

        self::assertSame(
            [
                'errors' => [['status' => '403', 'title' => 'Forbidden']],
                'links' => ['about' => 'https://example.com/errors'],
                'meta' => ['requestId' => 'abc'],
                'jsonapi' => ['version' => '1.1'],
            ],
            ErrorRenderer::document(
                [$error],
                new JsonApiObject(),
                ['requestId' => 'abc'],
                new DocumentLinks(links: ['about' => new Link('https://example.com/errors')]),
            ),
        );
    }

    #[Test]
    public function anExceptionRendersItsErrorsAndDeclaresItsStatus(): void
    {
        $exception = new AuthenticationRequired();

        self::assertSame(
            ['errors' => [['status' => '401', 'code' => 'AUTHENTICATION_REQUIRED', 'title' => 'Unauthorized']]],
            ErrorRenderer::document($exception),
        );
        self::assertSame(401, ErrorRenderer::status($exception));
    }

    #[Test]
    public function theStatusFollowsTheErrorsLikeAResponseDoes(): void
    {
        self::assertSame(422, ErrorRenderer::status([new Error(status: '422'), new Error(status: '422')]));
        self::assertSame(400, ErrorRenderer::status([new Error(status: '404'), new Error(status: '422')]));
        self::assertSame(500, ErrorRenderer::status([]));
    }

    #[Test]
    public function anEmptyErrorIsStillAnObjectInADocument(): void
    {
        self::assertSame('{"errors":[{}]}', \json_encode(ErrorRenderer::document([new Error()]), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function errorLinksBindToTheGivenBaseUnlessTheyPinnedTheirOwn(): void
    {
        $unbased = new Error(links: ErrorLinks::withoutBaseUri(new Link('/docs/conflict')));
        $pinned = new Error(links: ErrorLinks::withBaseUri('https://docs.example.com', new Link('/conflict')));

        self::assertSame(['links' => ['about' => '/docs/conflict']], ErrorRenderer::error($unbased));
        self::assertSame(['links' => ['about' => 'https://api.example.com/docs/conflict']], ErrorRenderer::error($unbased, baseUri: 'https://api.example.com'));
        self::assertSame(['links' => ['about' => 'https://docs.example.com/conflict']], ErrorRenderer::error($pinned, baseUri: 'https://api.example.com'));
    }

    #[Test]
    public function itRendersWhatAnErrorResponseRendersForTheSameErrors(): void
    {
        $errors = [
            new Error(status: '422', code: 'INVALID', title: 'Invalid', detail: 'Field {field} is wrong', source: ErrorSource::fromPointer('/data/attributes/name'), context: ['field' => 'name']),
            new Error(status: '422', meta: ['rule' => 'length']),
        ];

        $psr = ErrorResponse::fromErrors(...$errors)->toPsrResponse(new StubServer(), StubJsonApiRequest::create());
        $body = \json_decode($psr->getBody()->getContents(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(
            $body,
            \json_decode(\json_encode(ErrorRenderer::document($errors, new JsonApiObject()), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame($psr->getStatusCode(), ErrorRenderer::status($errors));
    }
}
