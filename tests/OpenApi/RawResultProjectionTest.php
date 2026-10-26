<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\ArticleSummary;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Raw\Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiProjector::class)]
#[CoversClass(RawResult::class)]
final class RawResultProjectionTest extends TestCase
{
    #[Test]
    public function rawResultsSitBesideTheJsonApiBodyAtTheirStatus(): void
    {
        $document = $this->project(
            new ActionResource('reports'),
            new RawResult('text/csv', schema: ['type' => 'string', 'x-delimiter' => ','], example: "a,b\n"),
            new RawResult('application/json', schema: ArticleSummary::class, status: 201),
        );

        $responses = ['paths', '/reports/-actions/export', 'post', 'responses'];
        self::assertSame(['application/vnd.api+json', 'text/csv'], \array_keys(self::arr(self::at($document, ...$responses, ...['200', 'content']))));
        self::assertSame(['type' => 'string', 'x-delimiter' => ','], self::at($document, ...$responses, ...['200', 'content', 'text/csv', 'schema']));
        self::assertSame("a,b\n", self::at($document, ...$responses, ...['200', 'content', 'text/csv', 'example']));
        self::assertSame(
            ['schema' => ['$ref' => '#/components/schemas/ArticleSummary']],
            self::at($document, ...$responses, ...['201', 'content', 'application/json']),
        );
        self::assertSame(ArticleSummary::jsonSchema(), self::at($document, 'components', 'schemas', 'ArticleSummary'));
        self::assertSame(['contract' => GeneratorContract::RAW_CONTENT], self::at($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aMediaTypeWithNoSchemaOrExampleCarriesNeither(): void
    {
        $response = self::at($this->project(new RawResult('text/plain')), 'paths', '/reports/-actions/export', 'post', 'responses', '200');

        self::assertSame('The action result.', self::at($response, 'description'));
        self::assertSame([], self::at($response, 'content', 'text/plain'));
    }

    #[Test]
    public function aSchemaClassWhoseNameIsTakenIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"Error"');

        $this->project(new RawResult('application/json', schema: Error::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function project(RawResult|ActionResource $first, RawResult|ActionResource ...$rest): array
    {
        $responds = [$first, ...\array_values($rest)];
        $types = [new FakeTypeMetadata(
            type: 'reports',
            uriType: 'reports',
            hasFields: false,
            actions: [new FakeActionMetadata('export', ['POST'], ActionScope::Collection, ActionInputMode::None, responds: $responds)],
        )];

        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project(new FakeServerMetadata('Raw', '1', $types))->toArray();
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = self::arr($value)[$key] ?? null;
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
