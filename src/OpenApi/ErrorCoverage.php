<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Exception;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresClientIdCollisions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentValidation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresHydratorCardinality;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIncludeSafeguards;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresInclusionUnsupported;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMaxIncludeDepth;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationSecurity;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresStrictQueryParameters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\ConditionalFieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;

/**
 * Which operations can raise each of core's error codes: the rule every core code follows,
 * keyed by its exception class in {@see RULES}, and what each rule covers.
 *
 * A rule names the operations whose runtime raises its codes, read from {@see OperationFacts}
 * as the operation projector reads them, and narrowed by what the type, relation or action
 * declares. Core reads what the definitions say itself, such as a `readOnly(when:)`
 * predicate, a relation's `cannotAdd(when:)` or an id encoder, through the fields a type
 * reports. What only an adapter knows, such as its security layer, its data layer's
 * refusals or the server's validation, it reports through an opt-in `Declares*` interface;
 * where neither tells, the rule offers the code.
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
    private const ACCESS = 'access';
    private const AUTHENTICATION = 'authentication';
    private const STRICT_FIELDSET = 'strict-fieldset';
    private const RESPONSE_VALIDATION = 'response-validation';
    private const REQUEST_VALIDATION = 'request-validation';
    private const ID_COLLISION = 'id-collision';
    private const ID_ENCODING = 'id-encoding';
    private const INCLUDE_UNSUPPORTED = 'include-unsupported';
    private const INCLUDE_DEPTH = 'include-depth';
    private const RELATED_ATTRIBUTE = 'related-attribute';
    private const UPDATE_ID = 'update-id';
    private const ID_CONFLICT = 'id-conflict';
    private const CLIENT_ID_REFUSED = 'client-id-refused';
    private const CLIENT_ID_REQUIRED = 'client-id-required';
    private const REPLACEMENT = 'replacement';
    private const ADDITION = 'addition';
    private const REMOVAL = 'removal';
    private const FIXED_MEMBERSHIP = 'fixed-membership';
    private const RESOURCE = 'resource';
    private const RELATIONSHIP = 'relationship';
    private const INCLUDE = 'include';
    private const INCLUDE_NOT_ALLOWED = 'include-not-allowed';
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
        Exception\ApplicationError::class => self::EVERY,
        Exception\MediaTypeUnacceptable::class => self::EVERY,
        Exception\MediaTypeUnsupported::class => self::EVERY,
        Exception\MethodNotAllowed::class => self::EVERY,
        Exception\NoResourceRegistered::class => self::EVERY,
        Exception\QueryParamMalformed::class => self::EVERY,
        Exception\QueryParamUnrecognized::class => self::EVERY,

        Exception\AccessDenied::class => self::ACCESS,
        Exception\AuthenticationRequired::class => self::AUTHENTICATION,
        Exception\FieldsetMemberUnrecognized::class => self::STRICT_FIELDSET,
        Exception\ResponseBodyInvalidJson::class => self::RESPONSE_VALIDATION,
        Exception\ResponseBodyInvalidJsonApi::class => self::RESPONSE_VALIDATION,

        Exception\AtomicOperationsInvalid::class => self::BATCH,
        Exception\LocalIdConflict::class => self::BATCH,
        Exception\LocalIdNotFound::class => self::BATCH,

        Exception\RequestBodyInvalidJson::class => self::DOCUMENT,
        Exception\RequestBodyInvalidJsonApi::class => self::REQUEST_VALIDATION,
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

        Exception\ClientGeneratedIdAlreadyExists::class => self::ID_COLLISION,
        Exception\ResourceIdUndecodable::class => self::ID_ENCODING,
        Exception\ClientGeneratedIdNotSupported::class => self::CLIENT_ID_REFUSED,
        Exception\ClientGeneratedIdRequired::class => self::CLIENT_ID_REQUIRED,

        Exception\FullReplacementProhibited::class => self::REPLACEMENT,
        Exception\AdditionProhibited::class => self::ADDITION,
        Exception\RemovalProhibited::class => self::REMOVAL,
        Exception\MembershipChangeProhibited::class => self::FIXED_MEMBERSHIP,

        Exception\ResourceNotFound::class => self::RESOURCE,
        Exception\RelationshipNotExists::class => self::RELATIONSHIP,

        Exception\InclusionUnrecognized::class => self::INCLUDE,
        Exception\InclusionUnsupported::class => self::INCLUDE_UNSUPPORTED,
        Exception\InclusionDepthExceeded::class => self::INCLUDE_DEPTH,
        Exception\InclusionNotAllowed::class => self::INCLUDE_NOT_ALLOWED,
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
        switch ($rule) {
            case self::ACCESS:
            case self::AUTHENTICATION:
                return self::securityRule($server, $rule === self::ACCESS);
            case self::STRICT_FIELDSET:
                // Only a type declaring its field names can have an unknown member.
                return (!$server instanceof DeclaresStrictQueryParameters || $server->strictQueryParameters())
                    && self::anyType($server, static fn(TypeMetadataInterface $type): bool => $type->hasFields())
                    ? null
                    : [];
            case self::RESPONSE_VALIDATION:
                return !$server instanceof DeclaresDocumentValidation || $server->validatesResponseDocuments() ? null : [];
        }

        $inherit = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites;
        $always = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => true;
        $never = static fn(ServerMetadataInterface $server, bool $viaWrites): bool => false;

        [$covers, $batch] = match ($rule) {
            self::EVERY => [null, $always],
            self::BATCH => [static fn(): bool => false, $always],
            self::DOCUMENT => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::readsDocument($operation), $always],
            self::REQUEST_VALIDATION => self::requestValidationRule($server),
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
                    || self::checksHydratorCardinality(OperationFacts::hydratedType($type, $operation, $server)),
                $inherit,
            ],
            self::ATTRIBUTE_VALUE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::hydratesResource($operation)
                    || (self::mutatesRelationship($operation) && $operation->relation?->pivotFields() !== []),
                $inherit,
            ],
            self::READ_ONLY_ATTRIBUTE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::writesReadOnlyAttribute(
                    OperationFacts::hydratedType($type, $operation, $server),
                    $operation->key->kind !== OperationKind::Update,
                ),
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
            self::ID_COLLISION => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => ($created = self::createdType($type, $operation, $server)) !== null
                    && $created->allowsClientId()
                    && (!$created instanceof DeclaresClientIdCollisions || $created->detectsClientIdCollisions()),
                $inherit,
            ],
            self::ID_ENCODING => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => ($created = self::createdType($type, $operation, $server)) !== null
                    && $created->allowsClientId()
                    && self::decodesClientId($created),
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
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => match ($operation->key->kind) {
                    OperationKind::UpdateRelationship => $operation->relation !== null && self::guards($type, $operation->relation, Mode::Replace),
                    OperationKind::Update => self::anyDetachable($type, static fn(Metadata\RelationMetadataInterface $relation): bool => true),
                    default => false,
                },
                $inherit,
            ],
            self::REMOVAL => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => match ($operation->key->kind) {
                    OperationKind::RemoveRelationship => $operation->relation !== null && self::guards($type, $operation->relation, Mode::Remove),
                    OperationKind::UpdateRelationship => $operation->relation !== null && !$operation->relation->isToMany() && self::guards($type, $operation->relation, Mode::Remove),
                    // An update clears a to-one with `null`; a to-many it replaces.
                    OperationKind::Update => self::anyDetachable($type, static fn(Metadata\RelationMetadataInterface $relation): bool => !$relation->isToMany()),
                    default => false,
                },
                $inherit,
            ],
            self::ADDITION => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->key->kind === OperationKind::AddRelationship
                    && $operation->relation !== null
                    && RelationFacts::mutation($type, $operation->relation, Mode::Add) !== MutationPermission::Allowed,
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
                    OperationKind::Create => self::createMayMissLinkage($type),
                    OperationKind::Action => $operation->key->actionScope === ActionScope::Resource || OperationFacts::readsDocument($operation),
                    default => true,
                },
                $inherit,
            ],
            self::RELATIONSHIP => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $operation->relation !== null && match ($operation->key->kind) {
                    OperationKind::FetchRelated => RelationFacts::canBeAbsentFromRead($type, $operation->relation, false),
                    OperationKind::FetchRelationship => RelationFacts::canBeAbsentFromRead($type, $operation->relation, true),
                    default => true,
                },
                $inherit,
            ],
            self::INCLUDE => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::checksIncludeRoot($type, $operation, $server),
                $always,
            ],
            self::INCLUDE_UNSUPPORTED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $type instanceof DeclaresInclusionUnsupported
                    && $type->rejectsInclude($operation->key->withType($type->type())),
                $never,
            ],
            self::INCLUDE_NOT_ALLOWED => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::checksIncludeRoot($type, $operation, $server)
                    && self::refusesInclusion($type, $operation, $server),
                $always,
            ],
            self::INCLUDE_DEPTH => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::checksIncludeRoot($type, $operation, $server)
                    && self::limitsIncludeDepth($type, $operation, $server),
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
                || (OperationFacts::checksIncludeScopes($operation) && self::includablePaths($type, $operation) !== []),
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
     * Whether a hydrated type declares an attribute that can be read-only for a write in this
     * context: unconditionally, by a `readOnly(when:)` predicate, or by a field that does not
     * say ({@see ConditionalFieldInterface}).
     */
    private static function writesReadOnlyAttribute(?TypeMetadataInterface $hydrated, bool $creating): bool
    {
        foreach ($hydrated?->fields() ?? [] as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface) {
                continue;
            }
            if ($field->isReadOnly($creating) || !$field instanceof ConditionalFieldInterface || $field->hasReadOnlyPredicate($creating)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a hydrated type with no field inventory can refuse linkage of the wrong
     * cardinality: its hand-written hydrator checks it, or the metadata does not say.
     */
    private static function checksHydratorCardinality(?TypeMetadataInterface $hydrated): bool
    {
        return $hydrated !== null
            && !$hydrated->hasFields()
            && (!$hydrated instanceof DeclaresHydratorCardinality || $hydrated->checksRelationshipCardinality());
    }

    /**
     * Whether a client id sent to a created type is decoded, so one can be undecodable: its
     * `Id` field carries an encoder, or the type has no field inventory to tell.
     */
    private static function decodesClientId(TypeMetadataInterface $created): bool
    {
        if (!$created->hasFields()) {
            return true;
        }
        foreach ($created->fields() as $field) {
            if ($field instanceof Id) {
                return $field->encoder() !== null;
            }
        }

        return false;
    }

    /**
     * Whether a relationship mutation in `$mode` can be refused: the relation does not let
     * the mode through for every request, or, for a replacement or removal, the data layer
     * can refuse to detach a member.
     */
    private static function guards(TypeMetadataInterface $type, Metadata\RelationMetadataInterface $relation, Mode $mode): bool
    {
        return RelationFacts::mutation($type, $relation, $mode) !== MutationPermission::Allowed
            || RelationFacts::canRefuseDetachment($relation);
    }

    /**
     * Whether an update can send linkage replacing a relation `$which` accepts whose data
     * layer can refuse to detach a member. The relationship guards run only on the
     * relationship endpoints; an update meets the data layer's refusal alone.
     *
     * @param \Closure(Metadata\RelationMetadataInterface): bool $which
     */
    private static function anyDetachable(TypeMetadataInterface $type, \Closure $which): bool
    {
        foreach ($type->relations() as $relation) {
            if ($relation->allowsReplace() && $which($relation) && RelationFacts::canRefuseDetachment($relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a create can send linkage naming a resource the data layer cannot find: a
     * relation it may set resolves its linkage against storage that can miss.
     */
    private static function createMayMissLinkage(TypeMetadataInterface $type): bool
    {
        foreach ($type->relations() as $relation) {
            if (!RelationFacts::isReadOnlyOnCreate($type, $relation) && RelationFacts::canMissLinkage($relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the operation checks an `include` against the root of its document, up front or
     * when it renders.
     */
    private static function checksIncludeRoot(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        return OperationFacts::validatesInclude($operation) || OperationFacts::rendersDocument($type, $operation, $server);
    }

    /**
     * The roots an operation's `include` is checked against: the type whose safeguards apply,
     * `null` for a polymorphic related read, which has none of its own, and the types the
     * include tree starts from. A relationship document is rooted at the parent and reaches
     * the related types through the relation.
     *
     * @return list<array{TypeMetadataInterface|null, list<TypeMetadataInterface>}>
     */
    private static function includeRoots(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): array
    {
        $relation = $operation->relation;

        return match ($operation->key->kind) {
            OperationKind::FetchRelated => $relation === null ? [] : [[
                OperationFacts::relatedType($relation, $server),
                OperationFacts::renderedTypes($type, $operation, $server),
            ]],
            OperationKind::FetchRelationship,
            OperationKind::UpdateRelationship,
            OperationKind::AddRelationship,
            OperationKind::RemoveRelationship => [[$type, [$type, ...self::typesNamed($server, $relation?->relatedTypes() ?? [])]]],
            OperationKind::Action => \array_map(
                static fn(TypeMetadataInterface $rendered): array => [$rendered, [$rendered]],
                OperationFacts::renderedTypes($type, $operation, $server),
            ),
            default => [[$type, [$type]]],
        };
    }

    /**
     * `INCLUSION_NOT_ALLOWED`: a root's allow-list or excluded paths, or a relation the include
     * tree can reach that may not be included.
     */
    private static function refusesInclusion(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        foreach (self::includeRoots($type, $operation, $server) as [$root, $from]) {
            if ($root !== null && (!$root instanceof DeclaresIncludeSafeguards || $root->allowedIncludePaths() !== null || $root->excludedIncludePaths() !== [])) {
                return true;
            }

            $seen = [];
            $queue = $from;
            while (($current = \array_shift($queue)) !== null) {
                if (isset($seen[$current->type()])) {
                    continue;
                }
                $seen[$current->type()] = true;
                foreach ($current->relations() as $relation) {
                    if (RelationFacts::canRefuseInclusion($current, $relation)) {
                        return true;
                    }
                    if (!RelationFacts::isWriteOnly($relation)) {
                        \array_push($queue, ...self::typesNamed($server, $relation->relatedTypes()));
                    }
                }
            }
        }

        return false;
    }

    /**
     * `INCLUSION_DEPTH_EXCEEDED`: a root whose effective maximum depth, its own or else the
     * server's, is set, or unknown. A depth below one is no limit, as at runtime.
     */
    private static function limitsIncludeDepth(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): bool
    {
        foreach (self::includeRoots($type, $operation, $server) as [$root]) {
            if ($root !== null && !$root instanceof DeclaresIncludeSafeguards) {
                return true;
            }
            $own = $root instanceof DeclaresIncludeSafeguards ? $root->maxIncludeDepth() : null;
            if ($own !== null) {
                if ($own > 0) {
                    return true;
                }

                continue;
            }
            if (!$server instanceof DeclaresMaxIncludeDepth || ($server->maxIncludeDepth() ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * `REQUEST_BODY_INVALID_JSON_API`: every operation reading a document when the server
     * validates request documents or does not say; only a create or update, and the batch
     * entries running one, when it checks just their resource object's members.
     *
     * @return array{\Closure(TypeMetadataInterface, EnumeratedOperation): bool, \Closure(ServerMetadataInterface, bool): bool}
     */
    private static function requestValidationRule(ServerMetadataInterface $server): array
    {
        if (!$server instanceof DeclaresDocumentValidation || $server->validatesRequestDocuments()) {
            return [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::readsDocument($operation),
                static fn(ServerMetadataInterface $server, bool $viaWrites): bool => true,
            ];
        }
        $members = $server->checksResourceObjectMembers();

        return [
            static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $members
                && \in_array($operation->key->kind, [OperationKind::Create, OperationKind::Update], true),
            static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites,
        ];
    }

    /**
     * `ACCESS_DENIED` or `AUTHENTICATION_REQUIRED`: the operations the adapter's security layer
     * can refuse ({@see DeclaresOperationSecurity}), and the batch when it can be refused or
     * runs a write that can; in the shared shapes when that is every operation or the
     * metadata does not say.
     *
     * @return list<string>|null
     */
    private static function securityRule(ServerMetadataInterface $server, bool $access): ?array
    {
        if (!$server instanceof DeclaresOperationSecurity) {
            return null;
        }
        $ask = static fn(?TypeMetadataInterface $type, OperationKey $key): bool => $access
            ? $server->canDenyAccess($type, $key)
            : $server->canRequireAuthentication($type, $key);

        $operations = self::operations(
            $server,
            static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $ask($type, $operation->key->withType($type->type())),
            // A batch entry runs a write the security layer can refuse.
            static fn(ServerMetadataInterface $server, bool $viaWrites): bool => $viaWrites || $ask(null, OperationKey::atomic()),
        );
        $every = self::operations(
            $server,
            static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => true,
            static fn(ServerMetadataInterface $server, bool $viaWrites): bool => true,
        );

        return $operations === $every ? null : $operations;
    }

    /**
     * The server's types named `$names`, in order, skipping a name it does not describe.
     *
     * @param list<string> $names
     *
     * @return list<TypeMetadataInterface>
     */
    private static function typesNamed(ServerMetadataInterface $server, array $names): array
    {
        $types = [];
        foreach ($server->types() as $type) {
            if (\in_array($type->type(), $names, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Whether some type of the server satisfies `$predicate`.
     *
     * @param \Closure(TypeMetadataInterface): bool $predicate
     */
    private static function anyType(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            if ($predicate($type)) {
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
