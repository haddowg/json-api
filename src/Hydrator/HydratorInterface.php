<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

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
     * @throws ResourceTypeMissing|\haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed;
}
