<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every operation example the witness server projects validates against the schema it
 * sits beside. The link schemas describe links only by their shape, and a type's named
 * links sit in an `allOf` beside the shared resource links, so this is what shows the
 * examples and the schemas still agree.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
final class OperationExampleSchemaConformanceTest extends TestCase
{
    private const MEDIA_TYPE = 'application/vnd.api+json';

    private const ROOT = 'https://witness.example/openapi.json';

    #[Test]
    public function everyOperationExampleValidatesAgainstItsSchema(): void
    {
        $document = \json_decode((new OpenApiProjector())->project(ContractWitnessServer::build())->toJsonString(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        // One root holding the components and every example's schema, loaded once, so
        // each schema's `#/components/...` references resolve against it.
        $examples = [];
        $schemas = [];
        foreach ($this->examples($document) as $label => [$example, $schema]) {
            $key = 'e' . \count($examples);
            $examples[$key] = [$label, $example];
            $schemas[$key] = $schema;
        }
        $validator = new Validator();
        $validator->resolver()?->registerRaw((object) ['components' => $document->components, '$defs' => (object) $schemas], self::ROOT);

        foreach ($examples as $key => [$label, $example]) {
            $result = $validator->validate($example, (object) ['$ref' => self::ROOT . '#/$defs/' . $key]);
            self::assertTrue($result->isValid(), $label . ': ' . ($result->error()?->message() ?? ''));
        }

        self::assertGreaterThan(50, \count($examples));
    }

    /**
     * Each JSON:API example in the document, with the schema beside it.
     *
     * @return iterable<string, array{mixed, object}>
     */
    private function examples(\stdClass $document): iterable
    {
        foreach ((array) $document->paths as $path => $item) {
            foreach ((array) $item as $method => $operation) {
                if (!$operation instanceof \stdClass || !isset($operation->responses)) {
                    continue;
                }
                $request = $operation->requestBody->content->{self::MEDIA_TYPE} ?? null;
                if ($request instanceof \stdClass && \property_exists($request, 'example') && $request->schema instanceof \stdClass) {
                    yield "{$method} {$path} request" => [$request->example, $request->schema];
                }
                foreach ((array) $operation->responses as $status => $response) {
                    $media = $response->content->{self::MEDIA_TYPE} ?? null;
                    if ($media instanceof \stdClass && \property_exists($media, 'example') && $media->schema instanceof \stdClass) {
                        yield "{$method} {$path} {$status}" => [$media->example, $media->schema];
                    }
                }
            }
        }

        foreach ((array) $document->components->responses as $name => $response) {
            $media = $response->content->{self::MEDIA_TYPE} ?? null;
            if ($media instanceof \stdClass && \property_exists($media, 'example') && $media->schema instanceof \stdClass) {
                yield "response {$name}" => [$media->example, $media->schema];
            }
        }
    }
}
