<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Exception\CoreErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ErrorSourceShape;
use haddowg\JsonApi\OpenApi\Metadata\ContributesErrorCodes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMethodNotAllowed;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresReadOnlyAttributeRejection;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Schema\Profile\CountableProfile;

/**
 * Projects the server's {@see ErrorCatalog} into one schema component per error *shape*,
 * and the `anyOf` that offers them from `ErrorDocument.errors.items`.
 *
 * A shape is the codes at one status that share a `source` member and a `meta`
 * declaration, so a new code usually joins an existing shape as one more `code` branch
 * rather than adding a component and a union branch. Each code is a `const` branch of the
 * shape's `code`, carrying the code's own `title` and `description`. A shape narrows the
 * shared `Error` rather than restating it: `allOf: [$ref Error, {code: anyOf [const, …],
 * status: const, source?, meta?}]`. The `code` is an `anyOf` even with one branch, so
 * adding a code is always one more branch.
 *
 * A shape is named after its status, its `source` member and, when it declares `meta` or is
 * scoped, its first code: `Error403`, `Error422Pointer`, `Error403InsufficientScope`.
 *
 * **Scoped codes.** A descriptor declaring an {@see ErrorScope}, or given one by a
 * {@see \haddowg\JsonApi\Exception\ScopedErrorSource}, names the operations its code can
 * arrive on. Such a code is catalogued like any other, but in a shape of its own scope, so
 * where it can arrive is part of what groups it with other codes, and only the operations its
 * scope covers offer it ({@see scopedShapes()}).
 *
 * **The `anyOf` is a catalogue, not a constraint.** Its first branch is the open generic
 * `Error`, which every error object satisfies — including one carrying an application's
 * own code that no source declared. That is deliberate: a closed `oneOf` would make a
 * server's own error documents fail its own published schema the first time the
 * application threw something undeclared ([ADR 0053](../../docs/adr/0053-the-projected-error-code-catalogue-is-open.md)).
 *
 * Core's codes are always catalogued. A server whose metadata implements
 * {@see ContributesErrorCodes} adds its own on top, so the catalogue covers the errors
 * that server actually raises rather than only the ones core ships.
 *
 * The projection is registration-aware, like the rest of the document: a
 * code whose descriptor names an {@see ErrorFeature} the server does not offer is left
 * out entirely. A read-only server's client has no use for a typed
 * `RESOURCE_TYPE_UNACCEPTABLE` it can never receive.
 *
 * **Feature codes are scoped too.** When the server does offer the feature, a code that
 * declares no scope of its own is offered only on the operations that can raise it
 * ({@see featureCoverage()}): the cursor codes on the reads whose `page` is a cursor, the
 * request-document codes on the operations that read one, and so on. Each feature's codes
 * form shapes of their own, so `Error400ParameterCursorMalformed` keeps its name as the
 * operations it reaches change. A feature every operation can raise, such as
 * {@see ErrorFeature::OperationScopes}, stays in the shared shapes, and a declared scope
 * replaces the feature's.
 *
 * **{@see ErrorDescriptor::$context} is not projected.** It is the interpolation input
 * core fills into the `title` / `detail` templates and never reaches the wire.
 * The reason it is not a document property is the reason it is not a vendor extension
 * either. Whoever writes a replacement template writes PHP and reads the descriptor
 * directly; see `docs/errors-and-exceptions.md`.
 *
 * @internal
 */
final class ErrorCatalogProjector
{
    private readonly SchemaProjector $fields;

    /**
     * The last server's shapes and scoped shapes, so the per-operation lookups a projection
     * makes build them once.
     *
     * @var array{server: ServerMetadataInterface, shapes: array<string, array{status: int, source: ?ErrorSourceShape, meta: ?Schema, scope: ?string, descriptors: non-empty-list<ErrorDescriptor>}>, scoped: array<string, array<int, list<string>>>}|null
     */
    private ?array $memo = null;

    public function __construct()
    {
        $this->fields = new SchemaProjector();
    }

    /**
     * The scoped shapes `$operation` of `$type` offers, by status, in catalogue order: those
     * of the codes whose scope covers it. The Atomic Operations endpoint is `atomic` of the
     * empty type.
     *
     * @return array<int, list<string>>
     */
    public function scopedShapes(ServerMetadataInterface $server, string $type, string $operation): array
    {
        return $this->build($server)['scoped'][$type . "\0" . $operation] ?? [];
    }

    /**
     * Every shape's codes, keyed by shape name.
     *
     * @return array<string, non-empty-list<ErrorDescriptor>>
     */
    public function descriptorsByShape(ServerMetadataInterface $server): array
    {
        return \array_map(static fn(array $shape): array => $shape['descriptors'], $this->build($server)['shapes']);
    }

    /**
     * What a scoped shape's name adds to its status: the source and code it is named after,
     * such as `ArticleLocked` for `Error409ArticleLocked`.
     */
    public static function shapeSuffix(string $shape, int $status): string
    {
        return \substr($shape, \strlen('Error' . $status));
    }

    /**
     * The shape components, keyed by component name, in catalogue order of each shape's
     * first code — restricted to the codes this server can actually raise.
     *
     * @return array<string, Schema>
     */
    public function components(ServerMetadataInterface $server): array
    {
        $components = [];
        foreach ($this->build($server)['shapes'] as $name => $shape) {
            $components[$name] = $this->shapeSchema($shape['status'], $shape['source'], $shape['meta'], $shape['scope'] !== null, $shape['descriptors']);
        }

        return $components;
    }

    /**
     * The names of the shapes every operation can raise, grouped by the status each pins to
     * a `const` — the input to the per-status `ErrorDocument` narrowing. A status the
     * catalogue never claims is absent from the map, so the narrowing is derivable from the
     * catalogue alone. Scoped shapes are left out: only the operations they are scoped to
     * offer them. A status the catalogue claims only through scoped shapes maps to `[]`, so
     * an operation outside every scope narrows to the generic `Error` alone rather than
     * falling back to the document offering every shape.
     *
     * @return array<int, list<string>>
     */
    public function componentsByStatus(ServerMetadataInterface $server): array
    {
        $byStatus = [];
        foreach ($this->build($server)['shapes'] as $name => $shape) {
            if ($shape['scope'] === null) {
                $byStatus[$shape['status']][] = $name;
            } else {
                $byStatus[$shape['status']] ??= [];
            }
        }

        return $byStatus;
    }

    /**
     * The descriptors every operation of this server can raise, grouped by status, in
     * catalogue order: what each shared error response offers an example of.
     *
     * @return array<int, non-empty-list<ErrorDescriptor>>
     */
    public function descriptorsByStatus(ServerMetadataInterface $server): array
    {
        $byStatus = [];
        foreach ($this->build($server)['shapes'] as $shape) {
            if ($shape['scope'] !== null) {
                continue;
            }
            foreach ($shape['descriptors'] as $descriptor) {
                $byStatus[$descriptor->status][] = $descriptor;
            }
        }

        return $byStatus;
    }

    /**
     * The catalogue this server publishes: core's codes first, then whatever its metadata
     * contributes. Assembled per server, so an application's errors appear only on the
     * servers it registered them for.
     */
    public static function catalog(ServerMetadataInterface $server): ErrorCatalog
    {
        return new ErrorCatalog(...self::sources($server));
    }

    /**
     * Core's error source, then whatever the server's metadata contributes.
     *
     * @return list<\haddowg\JsonApi\Exception\ErrorCatalogSourceInterface>
     */
    private static function sources(ServerMetadataInterface $server): array
    {
        $sources = [new CoreErrorSource()];

        if ($server instanceof ContributesErrorCodes) {
            foreach ($server->errorSources() as $source) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * The schema for one entry of `ErrorDocument.errors`: the open generic `Error`
     * first, then every catalogued variant.
     *
     * With a `$status`, the entry belongs to a per-status narrowing (`ErrorDocument415`)
     * and `$components` holds only the codes pinned to that status. The generic branch
     * still leads, and it does more work there: a mixed-status document rounds down to
     * its status class,
     * so a `400` body may legitimately carry an error object whose own `status` reads
     * `"422"`.
     *
     * @param list<string> $components the variant component names, in emit order
     */
    public function errorsItemSchema(array $components, ?int $status = null): Schema
    {
        return $this->operationErrorsItemSchema($components)
            ->withDescription(
                $status === null
                    ? 'A JSON:API error object. The named shapes catalogue the codes this server '
                        . 'documents; the leading generic `Error` keeps the list open, so an error '
                        . 'carrying an undocumented `code` is still a valid error object.'
                    : 'A JSON:API error object in a `' . $status . '` document. The named shapes catalogue '
                        . 'the codes this server documents at that status; the leading generic `Error` keeps '
                        . 'the list open, so an error carrying an undocumented `code` — or one whose own '
                        . '`status` differs, which happens when a document of mixed statuses takes the '
                        . 'status class they round down to — is still a valid error object.',
            );
    }

    /**
     * The schema for one entry of an operation's own error document: the same branches as
     * {@see errorsItemSchema()}, without the description the shared documents carry.
     *
     * @param list<string> $components the variant component names, in emit order
     */
    public function operationErrorsItemSchema(array $components): Schema
    {
        $branches = [Schema::ref(ComponentNaming::schemaRef('Error'))];
        foreach ($components as $component) {
            $branches[] = Schema::ref(ComponentNaming::schemaRef($component));
        }

        return Schema::create()->withAnyOf($branches);
    }

    /**
     * The shape component name for codes at `$status` sharing `$source` and, when
     * `$firstCode` is given, the `meta` declaration or scope that code introduced.
     */
    public static function shapeName(int $status, ?ErrorSourceShape $source, ?string $firstCode): string
    {
        $name = 'Error' . $status;
        if ($source !== null) {
            $name .= \ucfirst($source->value);
        }
        if ($firstCode !== null) {
            $name .= ComponentNaming::base(\strtolower($firstCode));
        }

        return $name;
    }

    /**
     * The descriptors this server can actually raise, in catalogue order: the assembled
     * catalogue minus every code whose {@see ErrorFeature} the server does not offer.
     *
     * @return \Generator<int, ErrorDescriptor>
     */
    private function raisable(ServerMetadataInterface $server): \Generator
    {
        foreach (self::catalog($server)->descriptors() as $descriptor) {
            if ($descriptor->feature !== null && !$this->offers($descriptor->feature, $server)) {
                continue;
            }

            yield $descriptor;
        }
    }

    /**
     * The codes this server can raise, grouped into shapes by status, `source` member,
     * projected `meta` and scope, keyed by shape name in catalogue order of each shape's
     * first code; and each scoped operation's shapes by status.
     *
     * @return array{server: ServerMetadataInterface, shapes: array<string, array{status: int, source: ?ErrorSourceShape, meta: ?Schema, scope: ?string, descriptors: non-empty-list<ErrorDescriptor>}>, scoped: array<string, array<int, list<string>>>}
     */
    private function build(ServerMetadataInterface $server): array
    {
        if ($this->memo !== null && $this->memo['server'] === $server) {
            return $this->memo;
        }

        $descriptors = [];
        $byFeature = [];
        foreach ($this->raisable($server) as $descriptor) {
            $group = '';
            if ($descriptor->scope !== null) {
                $operations = self::operationsCovered($server, ...self::scopeCoverage($descriptor->scope));
            } elseif ($descriptor->feature !== null) {
                $feature = $descriptor->feature;
                if (!\array_key_exists($feature->value, $byFeature)) {
                    $byFeature[$feature->value] = $this->featureCoverage($feature, $server);
                }
                $operations = $byFeature[$feature->value];
                if ($operations === []) {
                    continue;
                }
                $group = $operations === null ? '' : $feature->value;
            } else {
                $operations = null;
            }
            $descriptors[] = [$descriptor, $operations, $group];
        }

        // A feature's codes share a shape only with that feature's, so a shape keeps its name
        // as the operations a feature reaches change.
        $shapes = [];
        $names = [];
        $members = [];
        $scopeOf = [];
        foreach ($descriptors as [$descriptor, $operations, $group]) {
            $meta = $this->metaSchema($descriptor);
            $scope = $operations === null ? null : \implode("\n", $operations);
            $key = $descriptor->status . "\0" . ($descriptor->source->value ?? '') . "\0"
                . ($meta === null ? '' : \json_encode($meta->toJson(), \JSON_THROW_ON_ERROR)) . "\0"
                . ($group !== '' ? $group : ($scope ?? ''));
            if (!isset($names[$key])) {
                $name = self::shapeName($descriptor->status, $descriptor->source, $meta === null && $scope === null ? null : $descriptor->code);
                $names[$key] = $name;
                $shapes[$name] = ['status' => $descriptor->status, 'source' => $descriptor->source, 'meta' => $meta, 'scope' => $scope];
                $scopeOf[$name] = $operations ?? [];
            }
            $members[$names[$key]][] = $descriptor;
        }

        $out = [];
        $scoped = [];
        foreach ($shapes as $name => $shape) {
            $out[$name] = $shape + ['descriptors' => $members[$name]];
            foreach ($scopeOf[$name] as $operation) {
                $scoped[$operation][$shape['status']][] = $name;
            }
        }

        return $this->memo = ['server' => $server, 'shapes' => $out, 'scoped' => $scoped];
    }

    /**
     * The operations of the server's types `$covers` accepts, as `<type>\0<operation name>`,
     * sorted. The Atomic Operations endpoint belongs to no type, so it is keyed under the
     * empty type. It is covered when `$atomic` says so, or when a covered operation is a
     * write a batch entry can perform, since the batch runs that write through the same
     * handler.
     *
     * @param \Closure(TypeMetadataInterface, EnumeratedOperation): bool $covers
     *
     * @return list<string>
     */
    private static function operationsCovered(ServerMetadataInterface $server, \Closure $covers, bool $atomic): array
    {
        $covered = [];
        $viaBatch = false;
        foreach ($server->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $enumerated) {
                if ($covers($type, $enumerated)) {
                    $covered[] = $type->type() . "\0" . $enumerated->key->name();
                    $viaBatch = $viaBatch || $enumerated->key->kind->isAtomicWrite();
                }
            }
        }
        if ($server->atomicOperations() !== null && ($viaBatch || $atomic)) {
            $covered[] = "\0" . OperationKey::atomic()->name();
        }
        \sort($covered, \SORT_STRING);

        return $covered;
    }

    /**
     * What a declared {@see ErrorScope} covers, as {@see operationsCovered()} takes it. A
     * scope naming a type the server does not register covers nothing there.
     *
     * @return array{\Closure(TypeMetadataInterface, EnumeratedOperation): bool, bool}
     */
    private static function scopeCoverage(ErrorScope $scope): array
    {
        return [
            static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => $scope->covers($type->type(), $operation->key),
            $scope->covers('', OperationKey::atomic()),
        ];
    }

    /**
     * The operations that can raise a code tied to `$feature`, as {@see operationsCovered()}
     * lists them, or `null` when every operation can. A code with no declared scope is offered
     * on these alone, so a server paging one collection by cursor does not offer the cursor
     * codes on its deletes. Each arm mirrors where the runtime raises the feature's codes.
     *
     * @return list<string>|null
     */
    private function featureCoverage(ErrorFeature $feature, ServerMetadataInterface $server): ?array
    {
        [$covers, $atomic] = match ($feature) {
            ErrorFeature::AtomicOperations => [static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => false, true],
            ErrorFeature::CursorPagination => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::pages(
                    $type,
                    $operation,
                    static fn(Schema $page): bool => self::carriesCursorProfile($page),
                ),
                false,
            ],
            ErrorFeature::PaginationMenu => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::pages(
                    $type,
                    $operation,
                    static fn(Schema $page): bool => \is_array($page->get('oneOf')),
                ),
                false,
            ],
            ErrorFeature::RelationshipCounts => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::withCountTokens($type, $operation, $server) !== [],
                false,
            ],
            ErrorFeature::ClientGeneratedIds => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::takesClientId($server, $type, $operation),
                false,
            ],
            ErrorFeature::Writes => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::readsDocument($operation),
                true,
            ],
            ErrorFeature::ReadOnlyAttributeRejection => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => OperationFacts::hydratesResource($operation),
                false,
            ],
            ErrorFeature::FixedMembership => [
                static fn(TypeMetadataInterface $type, EnumeratedOperation $operation): bool => self::guardsMembership($type, $operation),
                false,
            ],
            ErrorFeature::OperationScopes, ErrorFeature::MethodNotAllowed => [null, false],
        };

        return $covers === null ? null : self::operationsCovered($server, $covers, $atomic);
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
     * Whether the operation can take a client-generated id: a create of a type that permits
     * one, or an action whose document input is such a type, since the action hydrates it as
     * a create.
     */
    private static function takesClientId(ServerMetadataInterface $server, TypeMetadataInterface $type, EnumeratedOperation $operation): bool
    {
        $target = match (true) {
            $operation->key->kind === OperationKind::Create => $type,
            OperationFacts::hydratesResource($operation) && $operation->action !== null => self::typeNamed($server, $operation->action->inputType() ?? $type->type()),
            default => null,
        };

        return $target !== null && ($target->allowsClientId() || $target->requiresClientId());
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

    private static function typeNamed(ServerMetadataInterface $server, string $name): ?TypeMetadataInterface
    {
        foreach ($server->types() as $type) {
            if ($type->type() === $name) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The closed `meta` object a descriptor declares, or `null` when it declares none. A
     * member declared `required()` is required; any other is optional.
     */
    private function metaSchema(ErrorDescriptor $descriptor): ?Schema
    {
        if ($descriptor->meta === []) {
            return null;
        }

        return $this->fields->projectField(Obj::make('meta')->fields(...$descriptor->meta)->build(), true);
    }

    /**
     * One shape: the shared `Error`, narrowed by the two members a client may dispatch on
     * (`code` and `status` are the machine contract and are never resolver-overridable),
     * the `source` member every one of its codes always fills, and its declared `meta`.
     *
     * Each code is a `const` branch of `code` titled with the code's default `title` and
     * described by its `description`. `title` itself stays a plain string — a bound
     * {@see \haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface} replaces it per
     * locale — so the default is carried as an annotation rather than a `const` the server
     * would then violate. A code's example `detail` and `source` live in its entry of each
     * error response's `examples`, not on the schema.
     *
     * `meta` is required only when its declaration has a required member. A shape declaring
     * `meta` or scoped to some operations is titled after its first code, as it is named.
     *
     * @param non-empty-list<ErrorDescriptor> $descriptors
     */
    private function shapeSchema(int $status, ?ErrorSourceShape $source, ?Schema $meta, bool $scoped, array $descriptors): Schema
    {
        $codes = [];
        foreach ($descriptors as $descriptor) {
            $branch = Schema::ofType('string')->withConst($descriptor->code)->withTitle($descriptor->title);
            if ($descriptor->description !== null) {
                $branch = $branch->withDescription($descriptor->description);
            }
            $codes[] = $branch;
        }

        $required = ['code', 'status'];
        $narrowing = Schema::ofType('object')
            ->withProperty('code', Schema::create()->withAnyOf($codes))
            ->withProperty('status', Schema::ofType('string')->withConst((string) $status));

        if ($source !== null) {
            $narrowing = $narrowing->withProperty('source', Schema::ofType('object')->withRequired([$source->value]));
            $required[] = 'source';
        }

        if ($meta !== null) {
            $narrowing = $narrowing->withProperty('meta', $meta);
            $metaRequired = $meta->get('required');
            if (\is_array($metaRequired) && $metaRequired !== []) {
                $required[] = 'meta';
            }
        }

        $title = $meta !== null || $scoped
            ? $descriptors[0]->title
            : match ($source) {
                null => 'A ' . $status . ' error',
                ErrorSourceShape::Pointer => 'A ' . $status . ' error located by a pointer',
                ErrorSourceShape::Parameter => 'A ' . $status . ' error caused by a query parameter',
                ErrorSourceShape::Header => 'A ' . $status . ' error caused by a header',
            };

        $description = 'A `' . $status . '` error. Its `code` is one of the branches below, each '
            . 'describing what the code means and what a client should do about it.';
        $description .= match ($source) {
            null => '',
            ErrorSourceShape::Pointer => ' `source.pointer` locates the member of the request document at fault.',
            ErrorSourceShape::Parameter => ' `source.parameter` names the query parameter at fault.',
            ErrorSourceShape::Header => ' `source.header` names the request header at fault.',
        };

        return Schema::create()
            ->withTitle($title)
            ->withDescription($description)
            ->withAllOf([
                Schema::ref(ComponentNaming::schemaRef('Error')),
                $narrowing->withRequired($required),
            ]);
    }

    /**
     * Whether the server offers the capability an error depends on. Each arm answers the
     * question the {@see ErrorFeature} case states.
     */
    private function offers(ErrorFeature $feature, ServerMetadataInterface $server): bool
    {
        return match ($feature) {
            ErrorFeature::AtomicOperations => $server->atomicOperations() !== null,
            ErrorFeature::CursorPagination => $this->anyPageSchema(
                $server,
                static fn(Schema $page): bool => self::carriesCursorProfile($page),
            ),
            ErrorFeature::FixedMembership => RelationFacts::anyFixesMembership($server),
            ErrorFeature::PaginationMenu => $this->anyPageSchema(
                $server,
                static fn(Schema $page): bool => \is_array($page->get('oneOf')),
            ),
            ErrorFeature::RelationshipCounts => \in_array(CountableProfile::URI, $server->profiles(), true),
            ErrorFeature::ClientGeneratedIds => $this->anyType(
                $server,
                static fn($type): bool => $type->allowsClientId(),
            ),
            ErrorFeature::Writes => $this->exposesAWrite($server),
            ErrorFeature::ReadOnlyAttributeRejection => $server instanceof DeclaresReadOnlyAttributeRejection
                && $server->rejectsReadOnlyAttributes()
                && $this->exposesAWrite($server),
            ErrorFeature::OperationScopes => OperationScopes::of($server) !== null,
            ErrorFeature::MethodNotAllowed => $server instanceof DeclaresMethodNotAllowed
                && $server->rendersMethodNotAllowed(),
        };
    }

    /**
     * Whether any collection — a type's or a relationship's — has a page schema
     * satisfying `$predicate`.
     *
     * @param \Closure(Schema): bool $predicate
     */
    private function anyPageSchema(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            $page = $type->pageSchema();
            if ($page !== null && $predicate($page)) {
                return true;
            }

            foreach ($type->relations() as $relation) {
                $relationPage = $relation->pageSchema();
                if ($relationPage !== null && $predicate($relationPage)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a page schema paginates by cursor — the strategy marks itself with the
     * cursor profile URI, bare or as one arm of a {@see MultiPaginator} menu.
     */
    private static function carriesCursorProfile(Schema $page): bool
    {
        foreach (self::branches($page) as $branch) {
            if ($branch->extension('profile') === CursorPaginationProfile::URI) {
                return true;
            }
        }

        return false;
    }

    /**
     * A page schema and, when it is a menu, each of its `oneOf` arms.
     *
     * @return \Generator<int, Schema>
     */
    private static function branches(Schema $page): \Generator
    {
        yield $page;

        $oneOf = $page->get('oneOf');
        if (!\is_array($oneOf)) {
            return;
        }

        foreach ($oneOf as $branch) {
            if ($branch instanceof Schema) {
                yield $branch;
            }
        }
    }

    /**
     * Whether any registered type satisfies `$predicate`.
     *
     * @param \Closure(\haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface): bool $predicate
     */
    private function anyType(ServerMetadataInterface $server, \Closure $predicate): bool
    {
        foreach ($server->types() as $type) {
            if ($predicate($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the server accepts a request document anywhere: a CRUD write on some type,
     * or the atomic batch endpoint. Everything that parses or hydrates a body hangs off
     * this.
     *
     * A relationship mutation needs no branch of its own: it rides on its type's
     * {@see OperationType::Update}, so a type permissive enough to project one is already
     * caught by the CRUD loop.
     */
    private function exposesAWrite(ServerMetadataInterface $server): bool
    {
        if ($server->atomicOperations() !== null) {
            return true;
        }

        foreach ($server->types() as $type) {
            foreach ([OperationType::Create, OperationType::Update, OperationType::Delete] as $operation) {
                if (\in_array($operation, $type->operations(), true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
