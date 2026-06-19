<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

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
     * Whether a client may supply the resource `id` on create (`POST`) — gates
     * whether the create request schema includes (and may require) `id`.
     */
    public function allowsClientId(): bool;

    /**
     * The pagination strategy for this type's primary collection endpoint
     * (`GET /{type}`), already resolved against the server default by the metadata
     * source. {@see PaginatorKind::None} when the collection is unpaginated.
     */
    public function paginatorKind(): PaginatorKind;

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
     * A human-readable description for the type, surfaced on its operations /
     * resource schema, or `null`.
     */
    public function description(): ?string;

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
