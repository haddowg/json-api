<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Document\DocumentInterface;

/**
 * Mutable per-document state threaded through the {@see DocumentTransformer}
 * during a single serialization pass. Not readonly: `$result` is built up
 * member by member as the document is transformed.
 *
 * Generic over the concrete document type so subclasses narrow `$document`
 * (resource vs error document) without an invariant-property conflict.
 *
 * @internal
 *
 * @template TDocument of DocumentInterface
 *
 */
abstract class AbstractDocumentTransformation
{
    /**
     * The document being transformed.
     *
     * @var TDocument
     */
    public DocumentInterface $document;

    public JsonApiRequestInterface $request;

    /**
     * Extra document-level meta merged on top of the document's own meta.
     *
     * @var array<string, mixed>
     */
    public array $additionalMeta;

    /**
     * The accumulated JSON:API document representation.
     *
     * @var array<string, mixed>
     */
    public array $result = [];

    /**
     * @param TDocument            $document
     * @param array<string, mixed> $additionalMeta
     */
    public function __construct(
        DocumentInterface $document,
        JsonApiRequestInterface $request,
        array $additionalMeta,
    ) {
        $this->document = $document;
        $this->request = $request;
        $this->additionalMeta = $additionalMeta;
    }
}
