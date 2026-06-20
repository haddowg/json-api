<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A write targeted an attribute flattened from a to-one relation
 * ({@see \haddowg\JsonApi\Resource\Field\AbstractField::on()}) whose related
 * model is absent: there is no owner object to write the value onto.
 *
 * Rendered as a 422 (require-exists). The flattened attribute cannot
 * auto-instantiate the related model — the relation must already be associated
 * (set it in the same request body, or on a resource that already has it) before
 * its flattened attribute can be written. The error points at the attribute the
 * client sent (`/data/attributes/<name>`), not the backing relation.
 */
final class RelatedAttributeOwnerMissing extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $attribute,
        public readonly string $relation,
    ) {
        parent::__construct(
            \sprintf(
                'Cannot write attribute "%s": its related "%s" is not present.',
                $attribute,
                $relation,
            ),
            422,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '422',
                code: 'RELATED_ATTRIBUTE_OWNER_MISSING',
                title: 'Related attribute owner missing',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/attributes/' . $this->attribute),
            ),
        ];
    }
}
