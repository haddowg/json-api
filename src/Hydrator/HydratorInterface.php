<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
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
