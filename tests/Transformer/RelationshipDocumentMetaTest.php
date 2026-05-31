<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Data\SingleResourceData;
use haddowg\JsonApi\Schema\Document\ResourceDocumentInterface;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relationship document MAY carry top-level jsonapi/meta/links per JSON:API 1.1.
 * yin omitted them on the relationship path; the engine now merges the document-level
 * members on top of the relationship's own data/meta/links.
 */
#[Group('spec:document-structure')]
final class RelationshipDocumentMetaTest extends TestCase
{
    #[Test]
    public function relationshipDocumentMergesDocumentLevelJsonApiAndMeta(): void
    {
        $document = new class implements ResourceDocumentInterface {
            public function getJsonApi(): JsonApiObject
            {
                return new JsonApiObject();
            }

            /**
             * @return array<string, mixed>
             */
            public function getMeta(): array
            {
                return ['docMeta' => true];
            }

            public function getLinks(): ?DocumentLinks
            {
                return null;
            }

            public function initializeTransformation(ResourceDocumentTransformation $transformation): void {}

            public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface
            {
                return new SingleResourceData();
            }

            /**
             * @return array<string, mixed>
             */
            public function getRelationshipData(
                ResourceDocumentTransformation $transformation,
                ResourceTransformer $transformer,
                DataInterface $data,
            ): array {
                return [
                    'data' => ['type' => 'people', 'id' => '9'],
                    'meta' => ['relMeta' => true],
                ];
            }

            public function clearTransformation(): void {}
        };

        $transformation = new ResourceDocumentTransformation(
            $document,
            null,
            new JsonApiRequest(new ServerRequest('GET', '/articles/1/relationships/author')),
            '',
            'author',
            [],
        );

        $result = (new DocumentTransformer())->transformRelationshipDocument($transformation)->result;

        self::assertSame(['type' => 'people', 'id' => '9'], $result['data']);
        self::assertSame(['version' => '1.1'], $result['jsonapi']);
        // document meta merged with the relationship's own meta (both present).
        self::assertSame(['docMeta' => true, 'relMeta' => true], $result['meta']);
    }
}
