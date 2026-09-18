<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * A client-generated resource id is well-formed but could not be decoded to a
 * storage key by the resource's {@see \haddowg\JsonApi\Resource\Field\IdEncoderInterface}.
 *
 * Rendered as a 422 — the safety net behind the create-id format constraint,
 * which already rejects a malformed id before hydration.
 */
final class ResourceIdUndecodable extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("The resource ID '$id' could not be decoded!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_ID_UNDECODABLE',
            status: 422,
            title: 'Resource ID is undecodable',
            context: ['id' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['id' => $this->id],
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
