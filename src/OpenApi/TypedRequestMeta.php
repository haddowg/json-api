<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\MetaShape;
use haddowg\JsonApi\Resource\RequestMeta;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Resource\ResourceMeta;

/**
 * Which write requests declare their top-level `meta`, what each request document is
 * named, and which types declare their resource objects' `meta`. {@see OperationProjector}
 * reads it to point a request body at the document, and {@see OpenApiProjector} to emit
 * it, so the two never disagree.
 *
 * A declaring write takes `<Operation>RequestDocument` (`ArticlesCreateRequestDocument`,
 * `ArticlesTagsAddRelationshipRequestDocument`, `ArticlesPublishActionRequestDocument`,
 * with the method appended for an action mounted on several). Each is an `allOf` of the
 * shared request document and an object narrowing `meta` to the declared object, marked
 * with an `x-request-meta` extension; a delete, and an action taking no document, has no
 * shared document and takes a meta-only one. A write that declares nothing keeps the
 * shared request document, or no body.
 *
 * A type declaring resource meta gets `<Type>ResourceMeta` for its resource object,
 * `<Type>CreateResourceMeta` and `<Type>UpdateResourceMeta` for its write requests' `data`,
 * each marked with an `x-resource-meta` extension.
 *
 * @internal
 *
 * @phpstan-type TypedRequest array{base: ?Schema, meta: MetaShape, operationId: string}
 */
final class TypedRequestMeta
{
    /**
     * The vendor extension, without its `x-` prefix, that marks a typed request document.
     */
    public const string EXTENSION = 'request-meta';

    /**
     * The vendor extension, without its `x-` prefix, that marks a declared resource meta object.
     */
    public const string RESOURCE_EXTENSION = 'resource-meta';

    /**
     * @var \WeakMap<object, array<string, MetaShape>>|null
     */
    private static ?\WeakMap $declared = null;

    /**
     * The members `$type` declares for the top-level `meta` of the write `$context` names.
     */
    public static function forOperation(TypeMetadataInterface $type, RequestMetaContext $context): MetaShape
    {
        return self::memo($type, 'request:' . $context->describe(), static fn(): MetaShape => RequestMeta::forOperation($type, $context, $type->type()));
    }

    /**
     * The members an action declares for the top-level `meta` of a call with `$method`.
     */
    public static function forAction(ActionMetadataInterface $action, string $method): MetaShape
    {
        return self::memo($action, 'action:' . \strtoupper($method), static fn(): MetaShape => RequestMeta::forAction($action, new ActionRequestMetaContext($method), \sprintf('"%s" action', $action->path())));
    }

    /**
     * The members `$type` declares for its resource objects' `meta` in `$context`'s direction.
     */
    public static function forResource(TypeMetadataInterface $type, MetaContext $context): MetaShape
    {
        $key = match (true) {
            $context->isRead() => 'resource:read',
            default => 'resource:' . ($context->creating() ? 'create' : 'update') . ($context->isAtomic() ? ':atomic' : ''),
        };

        return self::memo($type, $key, static fn(): MetaShape => ResourceMeta::forType($type, $context, $type->type()));
    }

    /**
     * The request context of a relationship mutation kind.
     */
    public static function relationshipContext(RelationMetadataInterface $relation, OperationKind $kind, bool $atomic = false): RequestMetaContext
    {
        return RequestMetaContext::forRelationship($relation->name(), self::mode($kind), $atomic);
    }

    /**
     * The mutation a relationship operation kind applies.
     */
    public static function mode(OperationKind $kind): Mode
    {
        return match ($kind) {
            OperationKind::AddRelationship => Mode::Add,
            OperationKind::RemoveRelationship => Mode::Remove,
            default => Mode::Replace,
        };
    }

    /**
     * The request document component of a write that declares `meta`.
     */
    public static function requestDocument(string $type, OperationKey $key, bool $methodQualified = false): string
    {
        return ComponentNaming::operation($type, $key, $methodQualified) . 'RequestDocument';
    }

    /**
     * The component of a type's resource object `meta` in a direction: `<Type>ResourceMeta`
     * for a read, `<Type>CreateResourceMeta` and `<Type>UpdateResourceMeta` for a write.
     */
    public static function resourceMetaComponent(string $type, ?OperationType $write = null): string
    {
        return ComponentNaming::base($type) . ($write === null ? '' : $write->value) . 'ResourceMeta';
    }

    /**
     * The `meta` of a resource object in a direction: a `$ref` to the type's component when
     * it declares one, else `$shared`.
     */
    public static function resourceMetaReference(TypeMetadataInterface $type, ?OperationType $write, Schema $shared): Schema
    {
        $context = $write === null ? MetaContext::read() : MetaContext::write($write);

        return self::forResource($type, $context)->isEmpty()
            ? $shared
            : Schema::ref(ComponentNaming::schemaRef(self::resourceMetaComponent($type->type(), $write)));
    }

    /**
     * What a request body points at: the operation's own document when it declares `meta`,
     * else the shared one, which is `null` for a write that takes no body.
     */
    public static function reference(?Schema $shared, string $document, MetaShape $meta): ?Schema
    {
        return $meta->isEmpty() ? $shared : Schema::ref(ComponentNaming::schemaRef($document));
    }

    /**
     * Whether an action mounted on several methods names its documents by method too.
     */
    public static function methodQualified(ActionMetadataInterface $action): bool
    {
        return \count($action->methods()) > 1;
    }

    /**
     * Every typed request document the server's writes take, by component name.
     *
     * @param \Closure(TypeMetadataInterface, ActionMetadataInterface): ?Schema $actionBase the shared request document of an action, or `null` when it takes none
     *
     * @return array<string, TypedRequest>
     */
    public static function documents(ServerMetadataInterface $server, \Closure $actionBase): array
    {
        $documents = [];
        foreach ($server->types() as $type) {
            $base = ComponentNaming::base($type->type());
            foreach (OperationEnumerator::forType($type) as $enumerated) {
                $key = $enumerated->key;
                if ($enumerated->action !== null) {
                    $action = $enumerated->action;
                    if ($action->inputMode() === ActionInputMode::Raw) {
                        continue;
                    }
                    $meta = self::forAction($action, $enumerated->method);
                    if (!$meta->isEmpty()) {
                        self::add($documents, self::requestDocument($type->type(), $key, self::methodQualified($action)), $actionBase($type, $action), $meta, $enumerated->operationId);
                    }

                    continue;
                }

                [$shared, $meta] = match ($key->kind) {
                    OperationKind::Create => [Schema::ref(ComponentNaming::schemaRef($base . 'CreateRequest')), self::forOperation($type, RequestMetaContext::forOperation(OperationType::Create))],
                    OperationKind::Update => [Schema::ref(ComponentNaming::schemaRef($base . 'UpdateRequest')), self::forOperation($type, RequestMetaContext::forOperation(OperationType::Update))],
                    OperationKind::Delete => [null, self::forOperation($type, RequestMetaContext::forOperation(OperationType::Delete))],
                    OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $enumerated->relation === null
                        ? [null, MetaShape::none()]
                        : [self::relationshipBase($type, $enumerated->relation), self::forOperation($type, self::relationshipContext($enumerated->relation, $key->kind))],
                    default => [null, MetaShape::none()],
                };
                if (!$meta->isEmpty()) {
                    self::add($documents, self::requestDocument($type->type(), $key), $shared, $meta, $enumerated->operationId);
                }
            }
        }

        return $documents;
    }

    /**
     * The shared request document of a relationship mutation: the relation's request
     * document when it carries pivot data, else its relationship document.
     */
    public static function relationshipBase(TypeMetadataInterface $type, RelationMetadataInterface $relation): Schema
    {
        $relBase = ComponentNaming::base($type->type()) . ComponentNaming::base($relation->name());

        return Schema::ref(ComponentNaming::schemaRef($relBase . (RelationFacts::hasPivot($relation) ? 'RelationshipRequest' : 'RelationshipDocument')));
    }

    /**
     * Whether any write declares its request `meta`, or any type its resource objects'
     * `meta`, which the generator contract reflects ({@see GeneratorContract::TYPED_REQUEST_META}).
     */
    public static function any(ServerMetadataInterface $server): bool
    {
        if (self::documents($server, static fn(): ?Schema => null) !== []) {
            return true;
        }
        foreach ($server->types() as $type) {
            foreach ([MetaContext::read(), MetaContext::write(OperationType::Create), MetaContext::write(OperationType::Update)] as $context) {
                if (!self::forResource($type, $context)->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A typed request document: the shared one, narrowed so its `meta` is the declared
     * object, required when any member is; or, for a write with no shared document, a
     * meta-only document.
     */
    public static function schema(?Schema $shared, MetaShape $meta, SchemaProjector $schemas, ?EnumComponentCollector $collector = null): Schema
    {
        $object = $schemas->projectMetaObject($meta, true, true, $collector);
        $required = self::hasRequired($meta, $schemas);

        if ($shared === null) {
            $document = Schema::ofType('object')
                ->withProperty('meta', $object)
                ->withProperty('jsonapi', Schema::ref(ComponentNaming::schemaRef('JsonApi')));

            return ($required ? $document->withRequired(['meta']) : $document)->withExtension(self::EXTENSION, true);
        }

        $narrowing = Schema::ofType('object')->withProperty('meta', $object);
        if ($required) {
            $narrowing = $narrowing->withRequired(['meta']);
        }

        return Schema::create()
            ->withAllOf([$shared, $narrowing])
            ->withExtension(self::EXTENSION, true);
    }

    /**
     * Whether a write must send `meta`: some member it may set is declared `required()`.
     */
    public static function hasRequired(MetaShape $meta, SchemaProjector $schemas): bool
    {
        foreach ($meta->writableFields(true) as $field) {
            if ($schemas->isRequired($field, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A type's resource object `meta` in a direction, as a component.
     */
    public static function resourceMetaSchema(MetaShape $meta, ?OperationType $write, SchemaProjector $schemas, ?EnumComponentCollector $collector = null): Schema
    {
        return $schemas->projectMetaObject($meta, $write !== null, $write !== OperationType::Update, $collector)
            ->withExtension(self::RESOURCE_EXTENSION, true);
    }

    /**
     * @param \Closure(): MetaShape $read
     */
    private static function memo(object $declarer, string $key, \Closure $read): MetaShape
    {
        $declared = self::$declared ??= new \WeakMap();
        $memo = $declared[$declarer] ?? [];
        if (!isset($memo[$key])) {
            $memo[$key] = $read();
            $declared[$declarer] = $memo;
        }

        return $memo[$key];
    }

    /**
     * @param array<string, TypedRequest> $documents
     */
    private static function add(array &$documents, string $name, ?Schema $shared, MetaShape $meta, string $operationId): void
    {
        if (isset($documents[$name])) {
            throw new \LogicException(\sprintf(
                'The operations "%s" and "%s" would both name their request document "%s". Rename a type, relation or action so their names differ.',
                $documents[$name]['operationId'],
                $operationId,
                $name,
            ));
        }
        $documents[$name] = ['base' => $shared, 'meta' => $meta, 'operationId' => $operationId];
    }
}
