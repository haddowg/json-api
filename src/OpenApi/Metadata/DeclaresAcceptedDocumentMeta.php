<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * Opt-in for a {@see TypeMetadataInterface} whose create or update `202` declares the
 * top-level `meta` of its job document, as
 * {@see \haddowg\JsonApi\Serializer\DeclaresAcceptedDocumentMetaInterface} does on the type
 * itself.
 */
interface DeclaresAcceptedDocumentMeta
{
    /**
     * The members of the top-level `meta` of the `202` the write `$operation` answers with.
     * It is called without a request.
     *
     * @return iterable<FieldInterface|FieldBuilderInterface>
     */
    public function acceptedDocumentMeta(OperationType $operation): iterable;
}
