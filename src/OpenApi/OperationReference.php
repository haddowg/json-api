<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

/**
 * Names one operation of the OpenAPI document: its path template (as the projector keys
 * it, see {@see PathTemplate}) and its lower-case HTTP method.
 *
 * A rendered link carries one to say which operation describes its target, and a
 * {@see DescribedByResolverInterface} turns it into the link's `describedby`.
 */
final readonly class OperationReference
{
    public string $method;

    public function __construct(
        public string $path,
        string $method,
    ) {
        $this->method = \strtolower($method);
    }

    public static function fetchCollection(string $uriType): self
    {
        return new self(PathTemplate::collection($uriType), 'get');
    }

    public static function create(string $uriType): self
    {
        return new self(PathTemplate::collection($uriType), 'post');
    }

    public static function fetchOne(string $uriType, bool $singleton = false): self
    {
        return new self(PathTemplate::resource($uriType, $singleton), 'get');
    }

    public static function update(string $uriType, bool $singleton = false): self
    {
        return new self(PathTemplate::resource($uriType, $singleton), 'patch');
    }

    public static function delete(string $uriType): self
    {
        return new self(PathTemplate::resource($uriType), 'delete');
    }

    public static function fetchRelated(string $uriType, string $relationship, bool $singleton = false): self
    {
        return new self(PathTemplate::related($uriType, $relationship, $singleton), 'get');
    }

    public static function fetchRelationship(string $uriType, string $relationship, bool $singleton = false): self
    {
        return new self(PathTemplate::relationship($uriType, $relationship, $singleton), 'get');
    }

    public static function action(string $uriType, string $action, ActionScope $scope, string $method, bool $singleton = false): self
    {
        return new self(PathTemplate::action($uriType, $action, $scope, $singleton), $method);
    }

    /**
     * The Atomic Operations endpoint's `POST`, at `$path` as the document keys it (the
     * value {@see Metadata\AtomicOperationsMetadataInterface::path()} returns).
     */
    public static function atomic(string $path): self
    {
        return new self($path, 'post');
    }

    /**
     * The operation a dispatched JSON:API operation was served as, or `null` when it
     * has no single path in the document (an Atomic Operations batch built without its
     * endpoint path).
     *
     * The URI segment is the type's {@see UriTypeAwareInterface::uriType()} when its
     * registered serializer declares one, else the JSON:API type, the same fallback the
     * by-convention links use. A relationship target names the relation, and its segment
     * is that relation's {@see AbstractRelationValue::uriFieldName()}. A singleton target
     * names its type's singleton paths, with no `{id}` segment.
     */
    public static function forOperation(JsonApiOperationInterface $operation, SerializerResolverInterface $resolver): ?self
    {
        if ($operation instanceof AtomicOperationsOperation) {
            $path = $operation->path();

            return $path === null ? null : self::atomic($path);
        }

        $target = $operation->target();
        $uriType = self::uriTypeOf($target->type, $resolver);
        $relationship = $target->relationship === null ? '' : self::uriFieldNameOf($target->type, $target->relationship, $resolver);
        $singleton = $target->singleton;

        return match (true) {
            $operation instanceof FetchResourceOperation => $target->addressesResource()
                ? self::fetchOne($uriType, $singleton)
                : self::fetchCollection($uriType),
            $operation instanceof CreateResourceOperation => self::create($uriType),
            $operation instanceof UpdateResourceOperation => self::update($uriType, $singleton),
            $operation instanceof DeleteResourceOperation => self::delete($uriType),
            $operation instanceof FetchRelatedOperation => self::fetchRelated($uriType, $relationship, $singleton),
            $operation instanceof FetchRelationshipOperation => self::fetchRelationship($uriType, $relationship, $singleton),
            $operation instanceof UpdateRelationshipOperation => new self(PathTemplate::relationship($uriType, $relationship, $singleton), 'patch'),
            $operation instanceof AddToRelationshipOperation => new self(PathTemplate::relationship($uriType, $relationship, $singleton), 'post'),
            $operation instanceof RemoveFromRelationshipOperation => new self(PathTemplate::relationship($uriType, $relationship, $singleton), 'delete'),
            $operation instanceof CustomActionOperation => self::action(
                $uriType,
                $operation->action(),
                $target->addressesResource() ? ActionScope::Resource : ActionScope::Collection,
                $operation->method(),
                $singleton,
            ),
            default => null,
        };
    }

    /**
     * The JSON Pointer (RFC 6901) to this operation inside the OpenAPI document, e.g.
     * `/paths/~1albums~1{id}/get`. Not URI-encoded; see {@see fragment()}.
     */
    public function pointer(): string
    {
        return '/paths/' . \str_replace(['~', '/'], ['~0', '~1'], $this->path) . '/' . $this->method;
    }

    /**
     * {@see pointer()} as a URI fragment (RFC 3986 §3.5): every character a fragment
     * may not carry is percent-encoded, so `{id}` becomes `%7Bid%7D`.
     */
    public function fragment(): string
    {
        return (string) \preg_replace_callback(
            '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/?]#',
            static fn(array $match): string => \rawurlencode($match[0]),
            $this->pointer(),
        );
    }

    private static function uriFieldNameOf(string $type, string $relationship, SerializerResolverInterface $resolver): string
    {
        if (!$resolver->hasSerializerFor($type)) {
            return $relationship;
        }

        $serializer = $resolver->serializerFor($type);
        $relation = $serializer instanceof DeclaresRelationsInterface ? $serializer->relationNamedIncludingHidden($relationship) : null;

        return $relation instanceof AbstractRelationValue ? $relation->uriFieldName() : $relationship;
    }

    private static function uriTypeOf(string $type, SerializerResolverInterface $resolver): string
    {
        if (!$resolver->hasSerializerFor($type)) {
            return $type;
        }

        $serializer = $resolver->serializerFor($type);
        if ($serializer instanceof UriTypeAwareInterface && $serializer->uriType() !== '') {
            return $serializer->uriType();
        }

        return $type;
    }
}
