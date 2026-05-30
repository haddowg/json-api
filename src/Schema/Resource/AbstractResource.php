<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Transformer\TransformerTrait;

/**
 * Convenience base for {@see ResourceInterface} implementations. Holds the
 * active request and domain object for the duration of a single serialization
 * pass and exposes the attribute-value {@see TransformerTrait} helpers.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractResource implements ResourceInterface
{
    use TransformerTrait;

    protected ?JsonApiRequestInterface $request = null;

    protected mixed $object = null;

    /**
     * @internal
     */
    public function initializeTransformation(JsonApiRequestInterface $request, mixed $object): void
    {
        $this->request = $request;
        $this->object = $object;
    }

    /**
     * @internal
     */
    public function clearTransformation(): void
    {
        $this->request = null;
        $this->object = null;
    }
}
