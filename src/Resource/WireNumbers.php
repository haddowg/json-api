<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Puts each number a write body carries in the PHP type its field documents, before
 * anything validates or hydrates it: a whole number written as `5.0` where an
 * {@see Field\Integer} is declared becomes the `int` `5`, and an `int` where a
 * {@see Field\Decimal} is declared becomes a `float`. JSON can't tell `5` from `5.0`, and
 * PHP decodes them as different types, so without it a strict comparison, a uniqueness
 * check or a write hook would see the two differently.
 *
 * It reaches every position a field declares: an attribute, a child of an `Obj`, `Map`
 * or `OneOf` variant, an open object's undeclared members, a list's elements, the member
 * a `Union` value selects, and the pivot values in a linkage member's `meta.pivot`. A
 * value of the wrong JSON type is left as it is, for validation to refuse.
 * {@see Server::dispatch()} normalizes each resource and relationship write; an adapter
 * normalizes an operation it handles without dispatching, such as one of an atomic
 * request, through {@see operation()}, and an action's input document through
 * {@see resourceDocument()}.
 */
final class WireNumbers
{
    /**
     * The operation with its write body normalized against the target type's resource,
     * or the operation unchanged when it carries no write body or the server has no
     * resource for the type.
     */
    public static function operation(JsonApiOperationInterface $operation, Server $server): JsonApiOperationInterface
    {
        $type = $operation->target()->type;
        if (!$server->hasResourceFor($type)) {
            return $operation;
        }
        $resource = $server->resourceFor($type);

        if ($operation instanceof CreateResourceOperation || $operation instanceof UpdateResourceOperation) {
            $normalize = static fn(JsonApiRequestInterface $request): JsonApiRequestInterface => self::resourceDocument($resource, $request);
        } elseif ($operation instanceof UpdateRelationshipOperation || $operation instanceof AddToRelationshipOperation || $operation instanceof RemoveFromRelationshipOperation) {
            $name = $operation->target()->relationship;
            $relation = $name === null ? null : $resource->relationNamedIncludingHidden($name);
            if ($relation === null) {
                return $operation;
            }
            $normalize = static fn(JsonApiRequestInterface $request): JsonApiRequestInterface => self::linkageDocument($relation, $request);
        } else {
            return $operation;
        }

        // The context's request is normalized too, for a handler that reads the body
        // through it.
        $body = $normalize($operation->body());
        $context = $operation->context();
        $httpRequest = $context->httpRequest();
        $normalizedHttp = $httpRequest instanceof JsonApiRequestInterface ? $normalize($httpRequest) : $httpRequest;
        if ($body === $operation->body() && $normalizedHttp === $httpRequest) {
            return $operation;
        }

        return $operation->withBody($body, $normalizedHttp === $httpRequest ? null : $context->withHttpRequest($normalizedHttp));
    }

    /**
     * A request whose body's `data` is a resource object of `$resource`'s type, with its
     * attributes and its relationships' pivot values normalized. The request itself
     * when nothing changes.
     */
    public static function resourceDocument(AbstractResource $resource, JsonApiRequestInterface $request): JsonApiRequestInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            return $request;
        }

        $data = self::resource($resource->allFields(), $body['data']);
        if ($data === $body['data']) {
            return $request;
        }
        $body['data'] = $data;

        return $request->withParsedBody($body);
    }

    /**
     * A request whose body's `data` is `$relation`'s linkage, with each member's pivot
     * values normalized. The request itself when nothing changes.
     */
    public static function linkageDocument(RelationInterface $relation, JsonApiRequestInterface $request): JsonApiRequestInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body) || !\array_key_exists('data', $body)) {
            return $request;
        }

        $data = self::linkage($relation, $body['data']);
        if ($data === $body['data']) {
            return $request;
        }
        $body['data'] = $data;

        return $request->withParsedBody($body);
    }

    /**
     * A resource object's attributes and pivot values normalized against `$fields`.
     *
     * @param iterable<FieldInterface> $fields
     * @param array<array-key, mixed>  $data
     *
     * @return array<array-key, mixed>
     */
    public static function resource(iterable $fields, array $data): array
    {
        $attributes = $data['attributes'] ?? null;
        $relationships = $data['relationships'] ?? null;

        foreach ($fields as $field) {
            $name = $field->name();
            if ($field instanceof RelationInterface) {
                if (\is_array($relationships) && \is_array($relationships[$name] ?? null) && \array_key_exists('data', $relationships[$name])) {
                    $relationships[$name]['data'] = self::linkage($field, $relationships[$name]['data']);
                }

                continue;
            }
            if ($field instanceof Id || !\is_array($attributes) || !\array_key_exists($name, $attributes)) {
                continue;
            }
            $attributes[$name] = self::value($field, $attributes[$name]);
        }

        if (\is_array($attributes)) {
            $data['attributes'] = $attributes;
        }
        if (\is_array($relationships)) {
            $data['relationships'] = $relationships;
        }

        return $data;
    }

    /**
     * A relationship's linkage, each member's `meta.pivot` values normalized against the
     * relation's pivot fields.
     */
    public static function linkage(RelationInterface $relation, mixed $linkage): mixed
    {
        if (!$relation instanceof PivotRelationInterface || $relation->pivotFields() === [] || !\is_array($linkage)) {
            return $linkage;
        }

        if (!\array_is_list($linkage)) {
            return self::member($relation, $linkage);
        }

        return \array_map(static fn(mixed $member): mixed => \is_array($member) ? self::member($relation, $member) : $member, $linkage);
    }

    /**
     * One value normalized against the field that declares it.
     */
    public static function value(FieldInterface $field, mixed $value): mixed
    {
        return $field instanceof AbstractFieldValue ? $field->normalizeWireValue($value) : $value;
    }

    /**
     * @param array<array-key, mixed> $member
     *
     * @return array<array-key, mixed>
     */
    private static function member(PivotRelationInterface $relation, array $member): array
    {
        $meta = $member['meta'] ?? null;
        $pivot = \is_array($meta) ? $meta['pivot'] ?? null : null;
        if (!\is_array($meta) || !\is_array($pivot)) {
            return $member;
        }

        foreach ($relation->pivotFields() as $field) {
            if (\array_key_exists($field->name(), $pivot)) {
                $pivot[$field->name()] = self::value($field, $pivot[$field->name()]);
            }
        }
        $meta['pivot'] = $pivot;
        $member['meta'] = $meta;

        return $member;
    }
}
