<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Transformer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Data\SingleResourceData;
use haddowg\JsonApi\Schema\Document\ErrorDocumentInterface;
use haddowg\JsonApi\Schema\Document\ResourceDocumentInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Tests\Double\StubErrorDocument;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResourceDocument;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ErrorDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class DocumentTransformerTest extends TestCase
{
    #[Test]
    public function transformMetaDocumentWithoutJsonApiObject(): void
    {
        $document = $this->createDocument(null);

        $transformedDocument = $this->toMetaDocument($document, []);

        self::assertEquals([], $transformedDocument);
    }

    #[Test]
    public function transformMetaDocumentWithJsonApiObject(): void
    {
        $document = $this->createDocument(new JsonApiObject('1.0'));

        $transformedDocument = $this->toMetaDocument($document, []);

        self::assertEquals(
            [
                'jsonapi' => [
                    'version' => '1.0',
                ],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    public function transformMetaDocumentWithMeta(): void
    {
        $document = $this->createDocument(null, ['abc' => 'def']);

        $transformedDocument = $this->toMetaDocument($document, []);

        self::assertEquals(
            [
                'meta' => [
                    'abc' => 'def',
                ],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    public function transformMetaDocumentWithEmptyLinks(): void
    {
        $document = $this->createDocument(null, [], new DocumentLinks());

        $transformedDocument = $this->toMetaDocument($document, []);

        self::assertEquals(
            [
                'links' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    public function transformResourceDocumentWithEmptyData(): void
    {
        $document = $this->createDocument(null, [], null, new SingleResourceData());

        $transformedDocument = $this->toResourceDocument($document, []);

        self::assertEquals(
            [
                'data' => null,
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformResourceDocumentWithEmptyIncluded(): void
    {
        $document = $this->createDocument(null, [], null, new SingleResourceData());

        $transformedDocument = $this->toResourceDocument($document, [], new StubJsonApiRequest(['include' => 'animal']));

        self::assertEquals(
            [
                'data' => null,
                'included' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformRelationshipDocumentWithEmptyIncluded(): void
    {
        $document = $this->createDocument(
            null,
            [],
            null,
            new SingleResourceData(),
            [
                'data' => [],
            ],
        );

        $transformedDocument = $this->toRelationshipDocument($document, [], new StubJsonApiRequest(['include' => 'animal']));

        self::assertEquals(
            [
                'data' => [],
                'included' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformRelationshipDocumentWithIncluded(): void
    {
        $document = $this->createDocument(
            null,
            [],
            null,
            (new SingleResourceData())
                ->setIncludedResources(
                    [
                        [
                            'type' => 'user',
                            'id' => '2',
                        ],
                        [
                            'type' => 'user',
                            'id' => '3',
                        ],
                    ],
                ),
        );

        $transformedDocument = $this->toRelationshipDocument($document, []);

        self::assertEquals(
            [
                'included' => [
                    [
                        'type' => 'user',
                        'id' => '2',
                    ],
                    [
                        'type' => 'user',
                        'id' => '3',
                    ],
                ],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function transformRelationshipDocumentByIncludedQueryParam(): void
    {
        $document = $this->createDocument();

        $transformedDocument = $this->toRelationshipDocument($document, [], new StubJsonApiRequest(['include' => 'animal']));

        self::assertEquals(
            [
                'included' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function transformErrorDocumentWithoutJsonApiObject(): void
    {
        $document = $this->createErrorDocument(null);

        $transformedDocument = $this->toErrorDocument($document);

        self::assertEquals([], $transformedDocument);
    }

    #[Test]
    #[Group('spec:errors')]
    public function transformErrorDocumentWithJsonApiObject(): void
    {
        $document = $this->createErrorDocument(new JsonApiObject(''));

        $transformedDocument = $this->toErrorDocument($document);

        self::assertEquals(
            [
                'jsonapi' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function transformErrorDocumentWithMeta(): void
    {
        $document = $this->createErrorDocument(null, ['abc' => 'def']);

        $transformedDocument = $this->toErrorDocument($document);

        self::assertEquals(
            [
                'meta' => [
                    'abc' => 'def',
                ],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function transformErrorDocumentWithLinks(): void
    {
        $document = $this->createErrorDocument(null, [], new DocumentLinks());

        $transformedDocument = $this->toErrorDocument($document);

        self::assertEquals(
            [
                'links' => [],
            ],
            $transformedDocument,
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function transformErrorDocumentWithErrors(): void
    {
        $document = $this->createErrorDocument(null, [], null, [new Error(), new Error()]);

        $transformedDocument = $this->toErrorDocument($document);

        self::assertEquals(
            [
                'errors' => [
                    [],
                    [],
                ],
            ],
            $transformedDocument,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toMetaDocument(
        ResourceDocumentInterface $document,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        string $requestedRelationshipName = '',
    ): array {
        $transformation = new ResourceDocumentTransformation(
            $document,
            $object,
            $request ?? new StubJsonApiRequest(),
            '',
            $requestedRelationshipName,
            [],
        );

        return (new DocumentTransformer())->transformMetaDocument($transformation)->result;
    }

    /**
     * @return array<string, mixed>
     */
    private function toResourceDocument(
        ResourceDocumentInterface $document,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        string $requestedRelationshipName = '',
    ): array {
        $transformation = new ResourceDocumentTransformation(
            $document,
            $object,
            $request ?? new StubJsonApiRequest(),
            '',
            $requestedRelationshipName,
            [],
        );

        return (new DocumentTransformer())->transformResourceDocument($transformation)->result;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRelationshipDocument(
        ResourceDocumentInterface $document,
        mixed $object,
        ?JsonApiRequestInterface $request = null,
        string $requestedRelationshipName = '',
    ): array {
        $transformation = new ResourceDocumentTransformation(
            $document,
            $object,
            $request ?? new StubJsonApiRequest(),
            '',
            $requestedRelationshipName,
            [],
        );

        return (new DocumentTransformer())->transformRelationshipDocument($transformation)->result;
    }

    /**
     * @return array<string, mixed>
     */
    private function toErrorDocument(ErrorDocumentInterface $document, ?JsonApiRequestInterface $request = null): array
    {
        $transformation = new ErrorDocumentTransformation(
            $document,
            $request ?? new StubJsonApiRequest(),
            [],
        );

        return (new DocumentTransformer())->transformErrorDocument($transformation)->result;
    }

    /**
     * @param array<string, mixed>      $meta
     * @param array<string, mixed>|null $relationshipResponseContent
     */
    private function createDocument(
        ?JsonApiObject $jsonApi = null,
        array $meta = [],
        ?DocumentLinks $links = null,
        ?DataInterface $data = null,
        ?array $relationshipResponseContent = [],
    ): ResourceDocumentInterface {
        return new StubResourceDocument(
            $jsonApi,
            $meta,
            $links,
            $data,
            $relationshipResponseContent,
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<Error>          $errors
     */
    private function createErrorDocument(
        ?JsonApiObject $jsonApi = null,
        array $meta = [],
        ?DocumentLinks $links = null,
        array $errors = [],
    ): ErrorDocumentInterface {
        return new StubErrorDocument($jsonApi, $meta, $links, $errors);
    }
}
