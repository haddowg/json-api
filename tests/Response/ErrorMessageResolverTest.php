<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorMessageResolverTest extends TestCase
{
    #[Test]
    public function withoutAResolverAnErrorRendersItsInlineCopyUnchanged(): void
    {
        // Byte-identical default: the resolver-free path reproduces the catalogue's
        // own title/detail, context or no context.
        $errors = $this->render(
            new StubServer(),
            ErrorResponse::fromException(new MediaTypeUnsupported('text/plain')),
        );

        self::assertSame('The provided media type is unsupported', $errors[0]['title']);
        self::assertSame(
            "The media type 'text/plain' is unsupported in the 'Content-Type' header!",
            $errors[0]['detail'],
        );
    }

    #[Test]
    public function aResolverOverridesTitleByCodeLeavingCodeAndStatusIntact(): void
    {
        $resolver = $this->resolver(titles: ['RESOURCE_NOT_FOUND' => 'We could not find that']);

        $errors = $this->render(
            new StubServer(errorMessageResolver: $resolver),
            ErrorResponse::fromException(new ResourceNotFound()),
        );

        self::assertSame('We could not find that', $errors[0]['title']);
        // The machine + HTTP contract is untouched; only the human copy moved.
        self::assertSame('RESOURCE_NOT_FOUND', $errors[0]['code']);
        self::assertSame('404', $errors[0]['status']);
        // Detail was not resolved, so it keeps the catalogue default.
        self::assertSame('The requested resource is not found!', $errors[0]['detail']);
    }

    #[Test]
    public function aResolverLocalisesDetailByInterpolatingTheErrorContext(): void
    {
        $resolver = $this->resolver(details: [
            'MEDIA_TYPE_UNSUPPORTED' => "Le type de média '{mediaType}' n'est pas supporté par l'en-tête '{header}'.",
        ]);

        $errors = $this->render(
            new StubServer(errorMessageResolver: $resolver),
            ErrorResponse::fromException(new MediaTypeUnsupported('text/plain')),
        );

        // The localized template's {placeholders} are filled from the error's context.
        self::assertSame(
            "Le type de média 'text/plain' n'est pas supporté par l'en-tête 'Content-Type'.",
            $errors[0]['detail'],
        );
    }

    #[Test]
    public function theResolverAppliesUniformlyToAHostBuiltCodedError(): void
    {
        // A 422 the way an integration's validator builds it: coded, so it rides the
        // same seam — closing the hard-coded-title gap without special-casing.
        $resolver = $this->resolver(titles: ['VALIDATION_FAILED' => 'Entité non traitable']);

        $errors = $this->render(
            new StubServer(errorMessageResolver: $resolver),
            ErrorResponse::fromErrors(new Error(
                status: '422',
                code: 'VALIDATION_FAILED',
                title: 'Unprocessable Entity',
                detail: 'title: too long',
            )),
        );

        self::assertSame('Entité non traitable', $errors[0]['title']);
        self::assertSame('title: too long', $errors[0]['detail']);
    }

    #[Test]
    public function aPlaceholderWithNoMatchingContextKeyIsLeftLiteral(): void
    {
        $resolver = $this->resolver(details: [
            'MEDIA_TYPE_UNSUPPORTED' => 'Unsupported: {mediaType} (ref {ticket})',
        ]);

        $errors = $this->render(
            new StubServer(errorMessageResolver: $resolver),
            ErrorResponse::fromException(new MediaTypeUnsupported('text/plain')),
        );

        // {mediaType} is filled from context; {ticket} has no key, so it stays literal —
        // the render path never throws.
        self::assertSame('Unsupported: text/plain (ref {ticket})', $errors[0]['detail']);
    }

    #[Test]
    public function nullFromTheResolverKeepsTheDefaultPerSlot(): void
    {
        // Resolver knows the title but not the detail: the title moves, the detail
        // falls back to the catalogue default (graceful partial translation).
        $resolver = $this->resolver(titles: ['MEDIA_TYPE_UNSUPPORTED' => 'Nope']);

        $errors = $this->render(
            new StubServer(errorMessageResolver: $resolver),
            ErrorResponse::fromException(new MediaTypeUnsupported('text/plain')),
        );

        self::assertSame('Nope', $errors[0]['title']);
        self::assertSame(
            "The media type 'text/plain' is unsupported in the 'Content-Type' header!",
            $errors[0]['detail'],
        );
    }

    #[Test]
    public function contextIsInterpolationInputAndIsNeverSerialised(): void
    {
        $errors = $this->render(
            new StubServer(),
            ErrorResponse::fromErrors(new Error(
                status: '400',
                code: 'DEMO',
                title: 'Demo',
                detail: 'Demo',
                context: ['secret' => 'value'],
            )),
        );

        self::assertArrayNotHasKey('context', $errors[0]);
    }

    #[Test]
    public function catalogueExceptionsCarryContextForTheirDynamicParameters(): void
    {
        $error = (new MediaTypeUnsupported('application/xml'))->getErrors()[0];

        self::assertSame(['mediaType' => 'application/xml', 'header' => 'Content-Type'], $error->context);
    }

    /**
     * @param array<string, string> $titles
     * @param array<string, string> $details
     */
    private function resolver(array $titles = [], array $details = []): ErrorMessageResolverInterface
    {
        return new class ($titles, $details) implements ErrorMessageResolverInterface {
            /**
             * @param array<string, string> $titles
             * @param array<string, string> $details
             */
            public function __construct(
                private readonly array $titles,
                private readonly array $details,
            ) {}

            public function title(string $code): ?string
            {
                return $this->titles[$code] ?? null;
            }

            public function detail(string $code): ?string
            {
                return $this->details[$code] ?? null;
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function render(StubServer $server, ErrorResponse $response): array
    {
        $psr = $response->toPsrResponse($server, StubJsonApiRequest::create());

        /** @var array{errors: list<array<string, mixed>>} $body */
        $body = \json_decode($psr->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['errors'];
    }
}
