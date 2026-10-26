<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionResponses::class)]
final class ActionResponsesTest extends TestCase
{
    #[Test]
    public function itAcceptsAUniqueValidActionResponseSet(): void
    {
        $this->expectNotToPerformAssertions();

        ActionResponses::validate([new ActionResource('exports'), new Accepted('jobs')]);
    }

    #[Test]
    public function itRejectsAnEmptySet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([]);
    }

    #[Test]
    public function itRejectsADuplicateStatusCode(): void
    {
        // ActionResource and MetaResult are both 200 — at most one 200 is meaningful.
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new ActionResource('exports'), new MetaResult()]);
    }

    #[Test]
    public function itRejectsMoreThanOneAsynchronousResponse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new Accepted('a'), new Accepted('b')]);
    }

    #[Test]
    public function itRejectsAResponseThatIsNotAnActionResponse(): void
    {
        // Ok is a valid CRUD/read response but is not a valid action response.
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new Ok()]);
    }

    #[Test]
    public function rawResultsSitBesideAJsonApiResponseAtOneStatus(): void
    {
        $this->expectNotToPerformAssertions();

        ActionResponses::validate([
            new ActionResource('exports'),
            new RawResult('text/csv'),
            new RawResult('application/pdf', binary: true),
            new RawResult('text/csv', status: 201),
        ]);
    }

    #[Test]
    public function aMediaTypeIsDeclaredOncePerStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new RawResult('text/csv'), new RawResult('Text/CSV; charset=utf-8')]);
    }

    #[Test]
    public function theMediaTypesPutJsonApiFirst(): void
    {
        self::assertSame(
            ['application/vnd.api+json', 'text/csv', 'application/pdf'],
            ActionResponses::mediaTypes([new RawResult('text/csv'), new MetaResult(), new RawResult('application/pdf'), new RawResult('text/csv', status: 201)]),
        );
        self::assertSame(['image/png'], ActionResponses::mediaTypes([new RawResult('image/png')]));
    }

    /**
     * @param array<string, mixed>|string|null $schema
     */
    #[Test]
    #[DataProvider('invalidRawResults')]
    public function aRawResultRefusesAnInvalidDeclaration(string $mediaType, array|string|null $schema, bool $binary, int $status): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new RawResult($mediaType, $schema, $binary, status: $status);
    }

    /**
     * @return array<string, array{string, array<string, mixed>|string|null, bool, int}>
     */
    public static function invalidRawResults(): array
    {
        return [
            'not a media type' => ['csv', null, false, 200],
            'wildcard' => ['image/*', null, false, 200],
            'json:api' => ['application/vnd.api+json', null, false, 200],
            'no content status' => ['text/csv', null, false, 204],
            'redirect status' => ['text/csv', null, false, 303],
            'binary and schema' => ['image/png', ['type' => 'string'], true, 200],
            'schema class without the interface' => ['application/json', \stdClass::class, false, 200],
        ];
    }
}
