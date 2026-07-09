<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * The OpenAPI-relevant metadata for one JSON:API type within a server — the input
 * the projector reads to emit that type's component schemas (this slice) and its
 * paths (Slice 3).
 *
 * **Field inventory may be absent.** A standalone-registered type (a serializer
 * with no {@see \haddowg\JsonApi\Resource\AbstractResource}) has no declared field
 * inventory: {@see hasFields()} is then `false` and {@see fields()} is empty, and
 * the projector emits a permissive resource-object schema. Everything else (id
 * pattern, operations, relations, tags) is independent of the field inventory.
 *
 * The bundle implements this in Slice 4 from its compiled registry + booted
 * resources; core projects purely against it and is fully testable with in-core
 * fixtures (no Symfony).
 */
interface TypeMetadataInterface
{
    /**
     * The JSON:API resource `type` (the wire identity, used as the `type` const in
     * the resource object and as the linkage `type`).
     */
    public function type(): string;

    /**
     * The URI segment this type is mounted under (ADR 0022 — distinct from
     * {@see type()}). Consumed by the Slice-3 path projection.
     */
    public function uriType(): string;

    /**
     * Whether this type declares a field inventory (a resource); `false` for a
     * standalone serializer with no declared fields.
     */
    public function hasFields(): bool;

    /**
     * The declared field inventory — attributes, the {@see
     * \haddowg\JsonApi\Resource\Field\Id} field, and relation fields — in declaration
     * order. Empty when {@see hasFields()} is `false`. The projector filters this to
     * attributes / id for the attribute + resource-object schemas (relations are
     * described via {@see relations()}).
     *
     * @return list<FieldInterface>
     */
    public function fields(): array;

    /**
     * The type's relationships, in declaration order.
     *
     * @return list<RelationMetadataInterface>
     */
    public function relations(): array;

    /**
     * The CRUD operations exposed for this type (the per-type allow-list). A
     * resource defaults to all five; a standalone serializer defaults to none.
     *
     * @return list<OperationType>
     */
    public function operations(): array;

    /**
     * The subset of {@see operations()} that carry a security expression — i.e. the
     * operations the projector emits with the configured per-operation security
     * requirement (the document-level {@see ServerMetadataInterface::defaultSecurity()},
     * per design §4.6 / D8). The contract carries only the **intent** (which
     * operations are secured); the *requirement* VOs themselves come from the
     * document default, never from parsing the authz expression. Mirrors
     * {@see ActionMetadataInterface::isSecured()} for custom actions.
     *
     * An operation absent from this list inherits the document-level default (the
     * projector emits no per-operation `security`); an operation present in it but
     * absent from {@see operations()} is ignored (it has no path to attach to).
     *
     * @return list<OperationType>
     */
    public function securedOperations(): array;

    /**
     * The operations explicitly declared **public** — exempt from the document-level
     * default security. The projector emits an operation-level `security: []` (the OAS
     * "no auth" override) and no `401` for them. An operation in neither this set nor
     * {@see securedOperations()} inherits the document default; the two sets are
     * disjoint.
     *
     * @return list<OperationType>
     */
    public function publicOperations(): array;

    /**
     * Whether a client may supply the resource `id` on create (`POST`) — gates
     * whether the create request schema includes (and may require) `id`.
     */
    public function allowsClientId(): bool;

    /**
     * Whether a client MUST supply the resource `id` on create (`POST`) — a create
     * without it is rejected (`403`). Implies {@see allowsClientId()}. Gates whether the
     * create request schema marks `id` as `required` (vs merely permitted).
     */
    public function requiresClientId(): bool;

    /**
     * The (un-anchored) regular expression an `id` must match for this type — the
     * same pattern that constrains the `{id}` route requirement (e.g. an encoded or
     * ULID id), or `null` when any non-empty string is accepted. Lets the OpenAPI
     * `{id}` path parameter advertise exactly what the router will accept, so a
     * documented id and an accepted id can never diverge.
     */
    public function idPattern(): ?string;

    /**
     * The `page[…]` value schema for this type's primary collection endpoint
     * (`GET /{type}`) — the object schema of the resolved paginator
     * ({@see \haddowg\JsonApi\Pagination\PaginatorInterface::describePageSchema()}),
     * or the `oneOf` menu of a {@see \haddowg\JsonApi\Pagination\MultiPaginator}.
     * `null` when the collection is unpaginated (no `page` parameter at all). The
     * projector emits the whole `page` group as one `deepObject` query parameter
     * carrying this schema.
     */
    public function pageSchema(): ?Schema;

    /**
     * Whether this type's collection advertises `?withCount` (the collection-level
     * countability opt-in).
     */
    public function isCountable(): bool;

    /**
     * The filters exposed on this type's primary collection endpoint. Consumed by
     * the Slice-3 parameter projection.
     *
     * @return list<FilterInterface>
     */
    public function filters(): array;

    /**
     * The sorts exposed on this type's primary collection endpoint (the `sort`
     * parameter's allowed keys). Consumed by the Slice-3 parameter projection.
     *
     * @return list<SortInterface>
     */
    public function sorts(): array;

    /**
     * The custom actions mounted on this type.
     *
     * @return list<ActionMetadataInterface>
     */
    public function actions(): array;

    /**
     * The OpenAPI tag names every operation of this type is grouped under (already
     * resolved — explicit refs or the humanized-type default). The projector emits
     * these on each of the type's operations (Slice 3) and unions them into the
     * document-root tag set.
     *
     * @return list<string>
     */
    public function tags(): array;

    /**
     * A human-readable description for the type, surfaced on its resource-object
     * schema, or `null`. When `null` the projector emits a generated default naming
     * the type.
     */
    public function description(): ?string;

    /**
     * A human-readable description for one of this type's CRUD operations, surfaced
     * on that operation, or `null`. When `null` the projector emits a generated
     * default describing the operation. Independent of {@see description()} (which
     * describes the resource object), so a per-operation override never leaks into
     * the schema and vice versa.
     */
    public function operationDescription(OperationType $operation): ?string;

    /**
     * The resolved success-response set for a CRUD/read operation: the type's
     * declared override (validated via {@see OperationResponses::validate()}), else
     * {@see OperationResponses::defaultFor()}. The projector reads this only for the
     * operations present in {@see operations()}, emitting one OpenAPI response per
     * element (a `202` carrying its {@see OperationResponseInterface::jobType()}). A
     * type that declares no override returns the single default, so its projected
     * document is unchanged.
     *
     * @return non-empty-list<OperationResponseInterface>
     */
    public function responsesFor(OperationType $operation): array;

    /**
     * The relationship paths a `?include` may request for this type (respecting the
     * include safeguards: allow-list, depth, `cannotBeIncluded`), as dotted paths
     * (e.g. `author`, `author.company`). Consumed by the Slice-3 `include` parameter
     * projection.
     *
     * @return list<string>
     */
    public function includablePaths(): array;
}
