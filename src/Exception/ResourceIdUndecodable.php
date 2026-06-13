<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A client-generated resource id is well-formed but could not be decoded to a
 * storage key by the resource's {@see \haddowg\JsonApi\Resource\Field\IdEncoderInterface}.
 *
 * Rendered as a 422 — the safety net behind the create-id format constraint,
 * which already rejects a malformed id before hydration.
 */
final class ResourceIdUndecodable extends AbstractJsonApiException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("The resource ID '$id' could not be decoded!", 422);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '422',
                code: 'RESOURCE_ID_UNDECODABLE',
                title: 'Resource ID is undecodable',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
