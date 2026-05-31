<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Base hydrator for resources that support only the create (POST) operation.
 *
 */
abstract class AbstractCreateHydrator implements HydratorInterface
{
    use HydratorTrait;
    use CreateHydratorTrait;

    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws ResourceTypeMissing|JsonApiException
     *
     * @see CreateHydratorTrait::hydrateForCreate()
     */
    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $domainObject = $this->hydrateForCreate($request, $domainObject);

        $this->validateDomainObject($request, $domainObject);

        return $domainObject;
    }

    /**
     * Post-hydration validation hook.
     *
     * Called after the domain object has been fully hydrated. Override to add
     * cross-field or business-rule validation; the default implementation is
     * a no-op.
     *
     * @param mixed $domainObject
     */
    protected function validateDomainObject(JsonApiRequestInterface $request, mixed $domainObject): void {}
}
