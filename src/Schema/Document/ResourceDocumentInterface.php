<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * A document whose primary data is one or more resources.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
interface ResourceDocumentInterface extends DocumentInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getRelationshipData(
        ResourceDocumentTransformation $transformation,
        ResourceTransformer $transformer,
        DataInterface $data,
    ): ?array;

    /**
     * @internal
     */
    public function initializeTransformation(ResourceDocumentTransformation $transformation): void;

    /**
     * @internal
     */
    public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface;

    /**
     * @internal
     */
    public function clearTransformation(): void;
}
