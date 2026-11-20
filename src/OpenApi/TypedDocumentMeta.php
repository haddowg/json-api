<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\DocumentMeta;

/**
 * Which success documents declare their top-level `meta`, what each is named, and the
 * shared document each narrows. {@see OperationProjector} reads it to point a response at
 * the document, and {@see OpenApiProjector} to emit it, so the two never disagree.
 *
 * A declaring operation answers with `<Operation>Document` (`ArticlesFetchCollectionDocument`,
 * `ArticlesAuthorFetchRelatedDocument`), a declaring action with `<Type><Path>ActionDocument`
 * for its `200` and `<Type><Path>ActionCreatedDocument` for its `201`. Each is an `allOf` of
 * the shared document and an object narrowing `meta` to the declared members, marked with an
 * `x-document-meta` extension. An operation that declares nothing keeps the shared document.
 *
 * @internal
 *
 * @phpstan-type TypedDocument array{base: Schema, meta: DocumentMeta, operationId: string}
 */
final class TypedDocumentMeta
{
    /**
     * The vendor extension, without its `x-` prefix, that marks a typed-meta document.
     */
    public const string EXTENSION = 'document-meta';

    /**
     * Each type's declaration per operation, read once.
     *
     * @var \WeakMap<TypeMetadataInterface, array<string, DocumentMeta>>|null
     */
    private static ?\WeakMap $declared = null;

    /**
     * Each type's relationship write declarations, read once.
     *
     * @var \WeakMap<TypeMetadataInterface, array<string, DocumentMeta>>|null
     */
    private static ?\WeakMap $relationships = null;

    /**
     * The members `$type` declares for the document `$operation` answers with.
     */
    public static function forOperation(TypeMetadataInterface $type, OperationType $operation): DocumentMeta
    {
        self::$declared ??= new \WeakMap();
        $memo = self::$declared[$type] ?? [];
        if (!isset($memo[$operation->value])) {
            $memo[$operation->value] = DocumentMeta::forOperation($type, $operation, $type->type());
            self::$declared[$type] = $memo;
        }

        return $memo[$operation->value];
    }

    /**
     * The members of a related endpoint's document: the related type's `FetchCollection`
     * members for a to-many, its `FetchOne` members for a to-one. A polymorphic relation, or
     * one pointing at a type the server does not register, has none.
     */
    public static function forRelated(RelationMetadataInterface $relation, ServerMetadataInterface $server): DocumentMeta
    {
        $related = RelationFacts::monomorphicType($relation);
        foreach ($related === null ? [] : $server->types() as $candidate) {
            if ($candidate->type() === $related) {
                return self::forOperation($candidate, $relation->isToMany() ? OperationType::FetchCollection : OperationType::FetchOne);
            }
        }

        return new DocumentMeta();
    }

    /**
     * The members of the meta-only document a delete answers `200` with.
     */
    public static function forDelete(TypeMetadataInterface $type): DocumentMeta
    {
        return DocumentMeta::forDelete(self::forOperation($type, OperationType::Delete), $type->responsesFor(OperationType::Delete), $type->type());
    }

    /**
     * The members an action's declared response puts in its document's `meta`. An
     * {@see ActionResource} or {@see ActionCreated} whose type the server does not register
     * answers with an inline document and has none.
     */
    public static function forActionResponse(ActionResponse $response, ServerMetadataInterface $server): DocumentMeta
    {
        if (($response instanceof ActionResource || $response instanceof ActionCreated) && !self::registers($server, $response->bodyType())) {
            return new DocumentMeta();
        }
        if ($response instanceof Accepted && !self::registers($server, $response->jobType())) {
            return new DocumentMeta();
        }

        return DocumentMeta::forResponse($response);
    }

    /**
     * The document component of a CRUD or related operation that declares `meta`.
     */
    public static function operationDocument(string $type, OperationKey $key): string
    {
        return ComponentNaming::operation($type, $key) . 'Document';
    }

    /**
     * The `202` document of a CRUD write whose {@see Accepted} declares `meta`.
     */
    public static function acceptedDocument(string $type, OperationKey $key): string
    {
        return ComponentNaming::operation($type, $key) . 'AcceptedDocument';
    }

    /**
     * The members of the job document a create's or an update's `202` answers with.
     */
    public static function forAccepted(TypeMetadataInterface $type, OperationType $operation): DocumentMeta
    {
        return DocumentMeta::forAccepted($type, $operation, $type->responsesFor($operation), $type->type());
    }

    /**
     * The job type of the `202` a create or an update declares, or `null`.
     */
    public static function acceptedJobType(TypeMetadataInterface $type, OperationType $operation): ?string
    {
        foreach ($type->responsesFor($operation) as $response) {
            if ($response->status() === 202) {
                return $response->jobType();
            }
        }

        return null;
    }

    /**
     * The members of the meta-only `200` a relationship write answers with, which the
     * parent type declares for the write's kind. None when it declares none, so the write
     * answers its linkage.
     */
    public static function forRelationshipWrite(TypeMetadataInterface $type, OperationKind $kind): DocumentMeta
    {
        $operation = $kind->relationshipDocument() ?? throw new \InvalidArgumentException(\sprintf('%s is not a relationship write.', $kind->value));
        self::$relationships ??= new \WeakMap();
        $memo = self::$relationships[$type] ?? [];
        if (!isset($memo[$operation->value])) {
            $memo[$operation->value] = DocumentMeta::forOperation($type, $operation, $type->type());
            self::$relationships[$type] = $memo;
        }

        return $memo[$operation->value];
    }

    /**
     * The document component of an action response that declares `meta`: one per action,
     * whatever methods it answers to, and its `201` apart from its `200`.
     */
    public static function actionDocument(string $type, ActionMetadataInterface $action, ActionResponse $response): string
    {
        return ComponentNaming::base($type) . ComponentNaming::base($action->path()) . 'Action'
            . match (true) {
                $response instanceof ActionCreated => 'Created',
                $response instanceof Accepted => 'Accepted',
                default => '',
            } . 'Document';
    }

    /**
     * What a response points at: the operation's own document when it declares `meta`, else
     * the shared one.
     */
    public static function reference(Schema $shared, string $document, DocumentMeta $meta): Schema
    {
        return $meta->isEmpty() ? $shared : Schema::ref(ComponentNaming::schemaRef($document));
    }

    /**
     * Every typed-meta document the server's operations answer with, by component name.
     *
     * @return array<string, TypedDocument>
     */
    public static function documents(ServerMetadataInterface $server): array
    {
        $documents = [];
        foreach ($server->types() as $type) {
            $base = ComponentNaming::base($type->type());
            $actions = [];
            foreach (OperationEnumerator::forType($type) as $enumerated) {
                $key = $enumerated->key;
                if ($enumerated->action !== null) {
                    if (isset($actions[$enumerated->action->path()])) {
                        continue;
                    }
                    $actions[$enumerated->action->path()] = true;
                    foreach ($enumerated->action->responds() as $response) {
                        $shared = match (true) {
                            $response instanceof MetaResult => 'MetaDocument',
                            $response instanceof ActionResource, $response instanceof ActionCreated => ComponentNaming::base($response->bodyType()) . 'Document',
                            $response instanceof Accepted => ComponentNaming::base($response->jobType()) . 'Document',
                            default => null,
                        };
                        $meta = self::forActionResponse($response, $server);
                        if ($shared !== null && !$meta->isEmpty()) {
                            self::add($documents, self::actionDocument($type->type(), $enumerated->action, $response), $shared, $meta, $enumerated->operationId);
                        }
                    }

                    continue;
                }

                [$shared, $meta] = match ($key->kind) {
                    OperationKind::FetchCollection => self::declaresStatus($type, OperationType::FetchCollection, 200)
                        ? [$base . 'Collection', self::forOperation($type, OperationType::FetchCollection)] : [null, null],
                    OperationKind::FetchOne => self::declaresStatus($type, OperationType::FetchOne, 200)
                        ? [$base . 'Document', self::forOperation($type, OperationType::FetchOne)] : [null, null],
                    OperationKind::Create => self::declaresStatus($type, OperationType::Create, 201)
                        ? [$base . 'Document', self::forOperation($type, OperationType::Create)] : [null, null],
                    OperationKind::Update => self::declaresStatus($type, OperationType::Update, 200)
                        ? [$base . 'Document', self::forOperation($type, OperationType::Update)] : [null, null],
                    OperationKind::Delete => ['MetaDocument', self::forDelete($type)],
                    OperationKind::FetchRelated => $enumerated->relation === null ? [null, null] : [
                        $base . ComponentNaming::base($enumerated->relation->name()) . ($enumerated->relation->isToMany() ? 'RelatedCollection' : 'RelatedDocument'),
                        self::forRelated($enumerated->relation, $server),
                    ],
                    default => [null, null],
                };
                if ($shared !== null && $meta !== null && !$meta->isEmpty()) {
                    self::add($documents, self::operationDocument($type->type(), $key), $shared, $meta, $enumerated->operationId);
                }

                // A write accepted for later processing answers its job's document, which
                // its Accepted response may declare the meta of.
                if ($key->kind === OperationKind::Create || $key->kind === OperationKind::Update) {
                    $write = $key->kind === OperationKind::Create ? OperationType::Create : OperationType::Update;
                    $jobType = self::acceptedJobType($type, $write);
                    $accepted = $jobType === null || !self::registers($server, $jobType) ? null : self::forAccepted($type, $write);
                    if ($jobType !== null && $accepted !== null && !$accepted->isEmpty()) {
                        self::add($documents, self::acceptedDocument($type->type(), $key), ComponentNaming::base($jobType) . 'Document', $accepted, $enumerated->operationId);
                    }
                }

                // A relationship write the parent type declares document meta for answers a
                // meta-only document.
                if ($enumerated->relation !== null && \in_array($key->kind, [OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship], true)) {
                    $relationshipMeta = self::forRelationshipWrite($type, $key->kind);
                    if (!$relationshipMeta->isEmpty()) {
                        self::add($documents, self::operationDocument($type->type(), $key), 'MetaDocument', $relationshipMeta, $enumerated->operationId);
                    }
                }
            }
        }

        return $documents;
    }

    /**
     * Whether any operation the server exposes answers with a typed-meta document, which
     * the generator contract reflects ({@see GeneratorContract::TYPED_DOCUMENT_META}).
     */
    public static function any(ServerMetadataInterface $server): bool
    {
        return self::documents($server) !== [];
    }

    /**
     * A typed-meta document: the shared document, narrowed so its `meta` is a closed object
     * of the declared members, required when any member is. A collection's `meta` keeps the
     * `page` and `total` members core writes into it.
     */
    public static function schema(Schema $shared, DocumentMeta $meta, SchemaProjector $schemas, ?EnumComponentCollector $collector = null): Schema
    {
        $object = $schemas->projectMetaObject($meta->shape(), collector: $collector);
        if ($meta->collection) {
            $object = $object
                ->withProperty('page', Schema::ofType('object')->withDescription('The members of the current page, written by the server.'))
                ->withProperty('total', Schema::ofType('integer')->withMinimum(0)->withDescription('The size of the whole collection, when requested with `withCount=_self_`.'));
        }

        $narrowing = Schema::ofType('object')->withProperty('meta', $object);
        if ($meta->hasRequired()) {
            $narrowing = $narrowing->withRequired(['meta']);
        }

        return Schema::create()
            ->withAllOf([$shared, $narrowing])
            ->withExtension(self::EXTENSION, true);
    }

    /**
     * @param array<string, TypedDocument> $documents
     */
    private static function add(array &$documents, string $name, string $shared, DocumentMeta $meta, string $operationId): void
    {
        if (isset($documents[$name])) {
            throw new \LogicException(\sprintf(
                'The operations "%s" and "%s" would both name their document "%s". Rename a type, relation or action so their names differ.',
                $documents[$name]['operationId'],
                $operationId,
                $name,
            ));
        }
        $documents[$name] = ['base' => Schema::ref(ComponentNaming::schemaRef($shared)), 'meta' => $meta, 'operationId' => $operationId];
    }

    private static function declaresStatus(TypeMetadataInterface $type, OperationType $operation, int $status): bool
    {
        foreach ($type->responsesFor($operation) as $response) {
            if ($response->status() === $status) {
                return true;
            }
        }

        return false;
    }

    private static function registers(ServerMetadataInterface $server, string $type): bool
    {
        foreach ($server->types() as $candidate) {
            if ($candidate->type() === $type) {
                return true;
            }
        }

        return false;
    }
}
