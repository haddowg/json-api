<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
interface HydratorInterface
{
    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws ResourceTypeMissing|JsonApiException
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed;
}
