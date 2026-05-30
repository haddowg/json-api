<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * Base for resource documents. Holds the active request, primary domain object
 * and additional meta for the duration of a single transformation pass.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractResourceDocument implements ResourceDocumentInterface
{
    protected ?JsonApiRequestInterface $request = null;

    protected mixed $object = null;

    /**
     * @var array<string, mixed>
     */
    protected array $additionalMeta = [];

    /**
     * @internal
     */
    public function initializeTransformation(ResourceDocumentTransformation $transformation): void
    {
        $this->request = $transformation->request;
        $this->object = $transformation->object;
        $this->additionalMeta = $transformation->additionalMeta;
    }

    /**
     * @internal
     */
    public function clearTransformation(): void {}
}
