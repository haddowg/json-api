<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response\Internal;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Resource\Internal\RegisteredResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\RelatedResponse;

/**
 * The type of an operation's primary data, recorded on the response it answers with, so
 * a decorated primary serializer finds its Resource even when the collection is empty or
 * can't be read twice.
 *
 * @internal
 */
final class PrimaryType
{
    /**
     * @template TResponse of AbstractResponse|RawResponse
     *
     * @param TResponse $response
     *
     * @return TResponse
     */
    public static function record(AbstractResponse|RawResponse $response, JsonApiOperationInterface $operation, SerializerResolverInterface $resolver): AbstractResponse|RawResponse
    {
        if (!$response instanceof DataResponse && !$response instanceof RelatedResponse) {
            return $response;
        }

        $type = self::of($operation, $resolver);

        return $type === null ? $response : $response->withPrimaryType($type);
    }

    /**
     * The target type of a resource fetch, create or update, or the related type of a
     * related fetch whose relation relates to one type. `null` for anything else, such
     * as a custom action, whose data may be of any type.
     */
    public static function of(JsonApiOperationInterface $operation, SerializerResolverInterface $resolver): ?string
    {
        $target = $operation->target();
        if ($operation instanceof FetchResourceOperation || $operation instanceof CreateResourceOperation || $operation instanceof UpdateResourceOperation) {
            return $target->type;
        }
        if (!$operation instanceof FetchRelatedOperation || $target->relationship === null || !$resolver->hasSerializerFor($target->type)) {
            return null;
        }

        $types = RegisteredResource::of($resolver->serializerFor($target->type), $target->type, $resolver)
            ?->relationNamed($target->relationship)
            ?->relatedTypes() ?? [];

        return \count($types) === 1 ? $types[0] : null;
    }
}
