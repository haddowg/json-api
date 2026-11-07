<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Exception\DescribedErrorInterface;

/**
 * Type metadata that declares the described errors some of its operations raise and the
 * rest do not, so the document offers each code only where it can arrive.
 *
 * A code declared here is catalogued like any other, but only the operations it is
 * declared for list it: their error response for its status narrows to the codes every
 * operation can raise plus this one, and gains that status when the operation did not
 * already advertise it. A code the server catalogues for every operation, core's or one
 * contributed through {@see ContributesErrorCodes}, is unaffected by a declaration here.
 */
interface DeclaresOperationErrors extends TypeMetadataInterface
{
    /**
     * Operation name → the described error classes that operation raises, keyed like
     * {@see DeclaresOperationExamples::operationExamples()} (see {@see OperationKey}), with
     * an action named `action.<path>.<method>` and `*` for every operation of the type. A
     * key that is not an operation of the type fails the projection.
     *
     * @return array<string, list<class-string<DescribedErrorInterface>>>
     */
    public function operationErrors(): array;
}
