<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Exception;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;

/**
 * Which operations can raise each of core's error codes: the rule every core code follows,
 * keyed by its exception class in {@see RULES}, and what each rule covers.
 *
 * A rule names the operations whose runtime raises its codes, read from {@see OperationFacts}
 * as the operation projector reads them, and narrowed by what the type, relation or action
 * declares. Where the metadata cannot tell whether an operation raises a code, such as a
 * `readOnly(when:)` predicate or a relation's `cannotAdd(when:)`, the rule offers it.
 *
 * Each rule is a group: codes following one rule share shapes only with each other, so a
 * shape keeps its name as the operations a rule reaches change. A rule covering every
 * operation puts its codes in the shared shapes. A code no rule names, such as one an
 * application contributes, is offered on every operation unless it declares a scope, and a
 * declared {@see ErrorScope} always replaces a rule.
 *
 * The Atomic Operations endpoint is covered by each rule's batch arm: a batch entry runs a
 * write through the same handler, so most rules cover it when they cover a write a batch can
 * perform; some cover it always, and the codes a batch never raises, such as the top-level
 * member checks a batch document skips, never.
 *
 * @internal
 */
final class ErrorCoverage
{
    /** Every operation: the code stays in the shared shapes. */
    private const EVERY = 'every';
    private const BATCH = 'batch';
    private const DOCUMENT = 'document';
    private const DOCUMENT_MEMBERS = 'document-members';
    private const HYDRATED = 'hydrated';
    private const RESOURCE_TYPE = 'resource-type';
    private const LINKAGE = 'linkage';
    private const CARDINALITY = 'cardinality';
    private const ATTRIBUTE_VALUE = 'attribute-value';
    private const READ_ONLY_ATTRIBUTE = 'read-only-attribute';
    private const RELATED_ATTRIBUTE = 'related-attribute';
    private const UPDATE_ID = 'update-id';
    private const ID_CONFLICT = 'id-conflict';
    private const CLIENT_ID = 'client-id';
    private const CLIENT_ID_REFUSED = 'client-id-refused';
    private const CLIENT_ID_REQUIRED = 'client-id-required';
    private const REPLACEMENT = 'replacement';
    private const ADDITION = 'addition';
    private const REMOVAL = 'removal';
    private const FIXED_MEMBERSHIP = 'fixed-membership';
    private const RESOURCE = 'resource';
    private const RELATIONSHIP = 'relationship';
    private const INCLUDE = 'include';
    private const INCLUDE_ROOT = 'include-root';
    private const SORT_UNSUPPORTED = 'sort-unsupported';
    private const SORT_UNRECOGNIZED = 'sort-unrecognized';
    private const FILTER = 'filter';
    private const FILTER_VALUE = 'filter-value';
    private const CURSOR = 'cursor';
    private const PAGINATION_MENU = 'pagination-menu';
    private const COUNT = 'count';
    private const SCOPE = 'scope';

    /**
     * The rule each of core's codes follows. Every class core discovers in `src/Exception`
     * has an entry.
     *
     * @var array<class-string<Exception\DescribedErrorInterface>, string>
     */
    public const RULES = [
        Exception\AccessDenied::class => self::EVERY,
        Exception\ApplicationError::class => self::EVERY,
        Exception\AuthenticationRequired::class => self::EVERY,
        Exception\FieldsetMemberUnrecognized::class => self::EVERY,
        Exception\MediaTypeUnacceptable::class => self::EVERY,
        Exception\MediaTypeUnsupported::class => self::EVERY,
        Exception\MethodNotAllowed::class => self::EVERY,
        Exception\NoResourceRegistered::class => self::EVERY,
        Exception\QueryParamMalformed::class => self::EVERY,
        Exception\QueryParamUnrecognized::class => self::EVERY,
        Exception\ResponseBodyInvalidJson::class => self::EVERY,
        Exception\ResponseBodyInvalidJsonApi::class => self::EVERY,

        Exception\AtomicOperationsInvalid::class => self::BATCH,
        Exception\LocalIdConflict::class => self::BATCH,
        Exception\LocalIdNotFound::class => self::BATCH,

        Exception\RequestBodyInvalidJson::class => self::DOCUMENT,
        Exception\RequestBodyInvalidJsonApi::class => self::DOCUMENT,
        Exception\LocalIdNotSupported::class => self::DOCUMENT_MEMBERS,
        Exception\RequiredTopLevelMembersMissing::class => self::DOCUMENT_MEMBERS,
        Exception\TopLevelMemberNotAllowed::class => self::DOCUMENT_MEMBERS,
        Exception\TopLevelMembersIncompatible::class => self::DOCUMENT_MEMBERS,

        Exception\AttributeMemberUnrecognized::class => self::HYDRATED,
        Exception\DataMemberMissing::class => self::HYDRATED,
        Exception\ResourceIdInvalid::class => self::HYDRATED,
        Exception\ResourceTypeMissing::class => self::HYDRATED,
        Exception\ResourceTypeUnacceptable::class => self::RESOURCE_TYPE,
        Exception\ResourceIdentifierIdInvalid::class => self::LINKAGE,
        Exception\ResourceIdentifierIdMissing::class => self::LINKAGE,
        Exception\ResourceIdentifierLidInvalid::class => self::LINKAGE,
        Exception\ResourceIdentifierTypeInvalid::class => self::LINKAGE,
        Exception\ResourceIdentifierTypeMissing::class => self::LINKAGE,
        Exception\RelationshipTypeInappropriate::class => self::CARDINALITY,
        Exception\AttributeValueInvalid::class => self::ATTRIBUTE_VALUE,
        Exception\AttributeWriteProhibited::class => self::READ_ONLY_ATTRIBUTE,
        Exception\RelatedAttributeOwnerMissing::class => self::RELATED_ATTRIBUTE,
        Exception\ResourceIdMissing::class => self::UPDATE_ID,
        Exception\ResourceIdConflict::class => self::ID_CONFLICT,

        Exception\ClientGeneratedIdAlreadyExists::class => self::CLIENT_ID,
        Exception\ResourceIdUndecodable::class => self::CLIENT_ID,
        Exception\ClientGeneratedIdNotSupported::class => self::CLIENT_ID_REFUSED,
        Exception\ClientGeneratedIdRequired::class => self::CLIENT_ID_REQUIRED,

        Exception\FullReplacementProhibited::class => self::REPLACEMENT,
        Exception\AdditionProhibited::class => self::ADDITION,
        Exception\RemovalProhibited::class => self::REMOVAL,
        Exception\MembershipChangeProhibited::class => self::FIXED_MEMBERSHIP,

        Exception\ResourceNotFound::class => self::RESOURCE,
        Exception\RelationshipNotExists::class => self::RELATIONSHIP,

        Exception\InclusionUnrecognized::class => self::INCLUDE,
        Exception\InclusionUnsupported::class => self::INCLUDE,
        Exception\InclusionDepthExceeded::class => self::INCLUDE_ROOT,
        Exception\InclusionNotAllowed::class => self::INCLUDE_ROOT,
        Exception\SortingUnsupported::class => self::SORT_UNSUPPORTED,
        Exception\SortParamUnrecognized::class => self::SORT_UNRECOGNIZED,
        Exception\FilterParamUnrecognized::class => self::FILTER,
        Exception\FilterValueInvalid::class => self::FILTER_VALUE,
        Exception\CursorMalformed::class => self::CURSOR,
        Exception\CursorStale::class => self::CURSOR,
        Exception\PaginationKindUnknown::class => self::PAGINATION_MENU,
        Exception\RelationshipCountNotAllowed::class => self::COUNT,
        Exception\InsufficientScope::class => self::SCOPE,
    ];

    /**
     * Whether the rule `$class` follows offers it on every operation, in the shared shapes.
     * A class no rule names is offered on every operation too.
     *
     * @param class-string $class
     */
    public static function coversEveryOperation(string $class): bool
    {
        return (self::RULES[$class] ?? self::EVERY) === self::EVERY;
    }

    /**
     * Each rule's operations for the server last asked about, `null` for every operation.
     *
     * @var array<string, list<string>|null>
     */
    private array $memo = [];

    private ?ServerMetadataInterface $server = null;

    /**
     * Where a code can arrive: the group its shapes join, `''` for the shared shapes, and
     * its operations as `<type>\0<operation name>` (the Atomic Operations endpoint under the
     * empty type), sorted, or `null` for every operation. A declared scope wins; a code
     * whose class no rule names is offered everywhere.
     *
     * @param class-string $class
     *
     * @return array{string, list<string>|null}
     */
    public function of(ServerMetadataInterface $server, string $class, ErrorDescriptor $descriptor): array
    {
        if ($descriptor->scope !== null) {
            $operations = self::operations(
                $server,
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $descriptor->scope->covers($type->type(), $operation->key),
                static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites || $descriptor->scope->covers('', OperationKey::atomic()),
            );

            return ['scope:' . \implode("\n", $operations), $operations];
        }

        $rule = self::RULES[$class] ?? self::EVERY;
        if ($this->server !== $server) {
            $this->server = $server;
            $this->memo = [];
        }
        if (!\array_key_exists($rule, $this->memo)) {
            $this->memo[$rule] = $this->operationsOf($rule, $server);
        }
        $operations = $this->memo[$rule];

        return [$operations === null ? '' : $rule, $operations];
    }

    /**
     * @return list<string>|null
     */
    private function operationsOf(string $rule, ServerMetadataInterface $server): ?array
    {
        $inherit = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites;
        $always = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => true;
        $never = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => false;

        [$covers, $batch] = match ($rule) {
            self::EVERY => [null, $always],
            self::BATCH => [static fn(): bool => false, $always],
            self::DOCUMENT => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::readsDocument($operation), $always],
            self::DOCUMENT_MEMBERS => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::readsDocument($operation), $never],
            self::HYDRATED => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::hydratesResource($operation), $inherit],
            self::RESOURCE_TYPE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::hydratesResource($operation) || self::mutatesRelationship($operation),
                $inherit,
            ],
            self::LINKAGE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::mutatesRelationship($operation)
                    || self::hydratesLinkage(OperationFacts::hydratedType($type, $operation, $server), $operation),
                $inherit,
            ],
            self::CARDINALITY => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::mutatesRelationship($operation)
                    || OperationFacts::hydratedType($type, $operation, $server)?->hasFields() === false,
                $inherit,
            ],
            self::ATTRIBUTE_VALUE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::hydratesResource($operation)
                    || (self::mutatesRelationship($operation) && $operation->relation?->pivotFields() !== []),
                $inherit,
            ],
            self::READ_ONLY_ATTRIBUTE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::writesAttributes(OperationFacts::hydratedType($type, $operation, $server)),
                $inherit,
            ],
            self::RELATED_ATTRIBUTE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::writesRelatedAttribute(
                    OperationFacts::hydratedType($type, $operation, $server),
                    $operation->key->kind !== OperationKind::Update,
                ),
                $inherit,
            ],
            self::UPDATE_ID => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->key->kind === OperationKind::Update, $inherit],
            self::ID_CONFLICT => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->key->kind === OperationKind::Update, $never],
            self::CLIENT_ID => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::createdType($type, $operation, $server)?->allowsClientId() === true,
                $inherit,
            ],
            self::CLIENT_ID_REFUSED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => ($created = self::createdType($type, $operation, $server)) !== null
                    && !$created->allowsClientId() && !$created->requiresClientId(),
                $inherit,
            ],
            self::CLIENT_ID_REQUIRED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::createdType($type, $operation, $server)?->requiresClientId() === true,
                $inherit,
            ],
            self::REPLACEMENT => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->key->kind === OperationKind::UpdateRelationship
                    || ($operation->key->kind === OperationKind::Update && $type->relations() !== []),
                $inherit,
            ],
            self::REMOVAL => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => match ($operation->key->kind) {
                    OperationKind::RemoveRelationship => true,
                    OperationKind::UpdateRelationship => $operation->relation !== null && !$operation->relation->isToMany(),
                    OperationKind::Update => $type->relations() !== [],
                    default => false,
                },
                $inherit,
            ],
            self::ADDITION => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->key->kind === OperationKind::AddRelationship,
                // A batch entry can add to a relation that refuses additions outright,
                // whose endpoint the document therefore leaves out.
                static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites || self::anyRelation(
                    $server,
                    static fn(TypeMetadataInterface $type, Metadata\RelationMetadataInterface $relation): bool => $relation->isToMany() && !$relation->allowsAdd(),
                ),
            ],
            self::FIXED_MEMBERSHIP => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::guardsMembership($type, $operation), $inherit],
            self::RESOURCE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => match ($operation->key->kind) {
                    OperationKind::FetchCollection => false,
                    OperationKind::Create => $type->relations() !== [],
                    OperationKind::Action => $operation->key->actionScope === ActionScope::Resource || OperationFacts::readsDocument($operation),
                    default => true,
                },
                $inherit,
            ],
            self::RELATIONSHIP => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->relation !== null, $inherit],
            self::INCLUDE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::validatesInclude($operation)
                    || $operation->key->kind === OperationKind::FetchRelationship
                    || OperationFacts::renderedTypes($type, $operation, $server) !== [],
                $always,
            ],
            self::INCLUDE_ROOT => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::validatesInclude($operation)
                    || OperationFacts::rendersDocument($type, $operation, $server),
                $always,
            ],
            self::SORT_UNSUPPORTED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::sortVocabulary($type, $operation, $server) === []
                    || self::readsRelatedQuery($type, $operation, $server),
                $never,
            ],
            self::SORT_UNRECOGNIZED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => !\in_array(OperationFacts::sortVocabulary($type, $operation, $server), [null, []], true)
                    || self::readsRelatedQuery($type, $operation, $server),
                $never,
            ],
            self::FILTER => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::filterVocabulary($type, $operation, $server) !== null
                    || self::readsRelatedQuery($type, $operation, $server),
                $never,
            ],
            self::FILTER_VALUE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => !\in_array(OperationFacts::filterVocabulary($type, $operation, $server), [null, []], true)
                    || self::readsRelatedQuery($type, $operation, $server),
                $never,
            ],
            self::CURSOR => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::pages($type, $operation, static fn(Schema $page): bool => ErrorCatalogProjector::carriesCursorProfile($page)),
                $never,
            ],
            self::PAGINATION_MENU => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::pages($type, $operation, static fn(Schema $page): bool => \is_array($page->get('oneOf'))),
                $never,
            ],
            self::COUNT => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::rendersDocument($type, $operation, $server), $always],
            self::SCOPE => self::scopeRule($server),
            default => throw new \LogicException(\sprintf('No error coverage rule is named "%s".', $rule)),
        };

        return $covers === null ? null : self::operations($server, $covers, $batch);
    }

    /**
     * The operations of the server's types `$covers` accepts, as `<type>\0<operation name>`,
     * sorted. The Atomic Operations endpoint belongs to no type, so it is keyed under the
     * empty type; `$batch` decides it, told whether a covered operation is a write a batch
     * entry can perform.
     *
     * @param \Closure(TypeMetadataInterface, EnumeratedOperation): bool $covers
     * @param \Closure(ServerMetadataInterface, bool): bool              $batch
     *
     * @return list<string>
     */
    private static function operations(ServerMetadataInterface $server, \Closure $covers, \Closure $batch): array
    {
        $covered = [];
        $viaWrites = false;
        foreach ($server->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $enumerated) {
                if ($covers($type, $enumerated)) {
                    $covered[] = $type->type() . "\0" . $enumerated->key->name();
                    $viaWrites = $viaWrites || $enumerated->key->kind->isAtomicWrite();
                }
            }
        }
        if ($server->atomicOperations() !== null && $batch($server, $viaWrites)) {
            $covered[] = "\0" . OperationKey::atomic()->name();
        }
        \sort($covered, \SORT_STRING);

        return $covered;
    }

    /**
     * `INSUFFICIENT_SCOPE`: an operation needing scopes of its own, or one checking the
     * scopes of each `include` hop that has a path to include; the batch when it needs
     * scopes or runs a write that does.
     *
     * @return array{\Closure(TypeMetadataInterface, EnumeratedOperation): bool, \Closure(ServerMetadataInterface, bool): bool}
     */
    private static function scopeRule(ServerMetadataInterface $server): array
    {
        $scopes = OperationScopes::of($server);

        return [
            static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => ($scopes?->forOperation($operation) ?? []) !== []
                || (OperationFacts::validatesInclude($operation) && self::includablePaths($type, $operation) !== []),
            static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites || ($scopes?->atomic() ?? []) !== [],
        ];
    }

    /**
     * @return list<string>
     */
    private static function includablePaths(TypeMetadataInterface $type, EnumeratedOperation $operation): array
    {
        return $operation->key->kind === OperationKind::FetchRelated
            ? $operation->relation?->relatedIncludablePaths() ?? []
            : $type->includablePaths();
    }

    private static function readsRelatedQuery(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        return \in_array(RelationshipQueriesProfile::URI, $server->profiles(), true)
            && OperationFacts::readsRelatedQuery($type, $operation, $server);
    }

    private static function mutatesRelationship(EnumeratedOperation $operation): bool
    {
        return \in_array($operation->key->kind, [OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship], true);
    }

    /**
     * Whether a hydrated type can carry linkage in its document: it declares a relation,
     * or no field inventory, whose hand-written hydrator may read any.
     */
    private static function hydratesLinkage(?TypeMetadataInterface $hydrated, EnumeratedOperation $operation): bool
    {
        if (!OperationFacts::hydratesResource($operation)) {
            return false;
        }

        return $hydrated === null || !$hydrated->hasFields() || $hydrated->relations() !== [];
    }

    /**
     * Whether a hydrated type declares an attribute a write could send, read-only or not:
     * which of them is read-only for a request can rest on a predicate.
     */
    private static function writesAttributes(?TypeMetadataInterface $hydrated): bool
    {
        if ($hydrated === null) {
            return false;
        }
        foreach ($hydrated->fields() as $field) {
            if (!$field instanceof Id && !$field instanceof RelationInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a hydrated type declares an attribute flattened `on()` a relation that this
     * write does not unconditionally refuse.
     */
    private static function writesRelatedAttribute(?TypeMetadataInterface $hydrated, bool $creating): bool
    {
        foreach ($hydrated?->fields() ?? [] as $field) {
            if ($field->relatedVia() !== null && !$field->isReadOnly($creating)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type a create, or an action hydrating its input as a create, creates.
     */
    private static function createdType(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        return $operation->key->kind === OperationKind::Create || $operation->key->kind === OperationKind::Action
            ? OperationFacts::hydratedType($type, $operation, $server)
            : null;
    }

    /**
     * Whether the operation can replace the members of a relation declared
     * `cannotChangeMembership()`: a relationship replacement of that relation, or an update of
     * a type that has one, whose document may carry its linkage.
     */
    private static function guardsMembership(TypeMetadataInterface $type, EnumeratedOperation $operation): bool
    {
        if ($operation->key->kind === OperationKind::UpdateRelationship) {
            return $operation->relation !== null && RelationFacts::fixesMembership($operation->relation);
        }
        if ($operation->key->kind !== OperationKind::Update) {
            return false;
        }
        foreach ($type->relations() as $relation) {
            if (RelationFacts::fixesMembership($relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the operation takes a `page` parameter whose schema satisfies `$predicate`.
     *
     * @param \Closure(Schema): bool $predicate
     */
    private static function pages(TypeMetadataInterface $type, EnumeratedOperation $operation, \Closure $predicate): bool
    {
        $page = OperationFacts::pageSchema($type, $operation);

        return $page !== null && $predicate($page);
    }

    /**
     * Whether a relation of a type exposing updates satisfies `$predicate`.
     *
     * @param \Closure(TypeMetadataInterface, Metadata\RelationMetadataInterface): bool $predicate
     */
    private static function anyRelation(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            if (!\in_array(OperationType::Update, $type->operations(), true)) {
                continue;
            }
            foreach ($type->relations() as $relation) {
                if ($predicate($type, $relation)) {
                    return true;
                }
            }
        }

        return false;
    }
}
