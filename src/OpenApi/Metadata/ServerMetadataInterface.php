<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;

/**
 * A server's worth of JSON:API metadata — the **root** input the {@see
 * \haddowg\JsonApi\OpenApi\OpenApiProjector} consumes to build one OpenAPI document.
 *
 * This is the central seam (design §3, D2): core owns the JSON:API→OAS *semantics*
 * but most of the *data* (info, base URIs, server assignment, tag refs/definitions,
 * security schemes, the type list) is framework-/app-side. The bundle implements
 * this in Slice 4 from its compiled registry + config; core projects purely against
 * it and is fully testable with in-core fixtures.
 *
 * The accessors returning OAS value objects ({@see servers()}, {@see tags()},
 * {@see securitySchemes()}) are deliberately the already-built VO types: the
 * info/server/tag/security data is config-shaped and OAS-shaped (no JSON:API
 * semantics to project), so the contract carries it ready-made rather than
 * re-modelling it. Type/relation/action data, which *does* carry JSON:API semantics
 * the projector must interpret, is carried as the {@see TypeMetadataInterface}
 * family instead.
 */
interface ServerMetadataInterface
{
    /**
     * The API title (the OAS `info.title`).
     */
    public function title(): string;

    /**
     * The API version string (the OAS `info.version`).
     */
    public function version(): string;

    /**
     * A description for the API (the OAS `info.description`), or `null`.
     */
    public function description(): ?string;

    /**
     * The contact object for `info.contact`, or `null`.
     */
    public function contact(): ?\haddowg\JsonApi\OpenApi\Contact;

    /**
     * The license object for `info.license`, or `null`.
     */
    public function license(): ?\haddowg\JsonApi\OpenApi\License;

    /**
     * The OAS Server Objects (base URLs) the document advertises — one per base URI
     * the metadata source resolved for this server (or a combined set). Empty is
     * valid (the document then carries no `servers`).
     *
     * @return list<Server>
     */
    public function servers(): array;

    /**
     * The JSON:API version this server speaks (e.g. `1.1`) — surfaced in the
     * `jsonapi` object component schema.
     */
    public function jsonApiVersion(): string;

    /**
     * The document-root tag **definitions** (config-authoritative, with synthesis of
     * referenced-but-undefined tags), in emit order. The projector emits these
     * verbatim as the document `tags`.
     *
     * @return list<Tag>
     */
    public function tags(): array;

    /**
     * The named security schemes for `components.securitySchemes`, keyed by name.
     *
     * @return array<string, SecurityScheme>
     */
    public function securitySchemes(): array;

    /**
     * The document-level default security requirement (OR-ed alternatives), or an
     * empty list for no document-level default. The Slice-3 path projection applies
     * the per-operation requirement; this is the document-wide fallback.
     *
     * @return list<SecurityRequirement>
     */
    public function defaultSecurity(): array;

    /**
     * The external-documentation object for the document root, or `null`.
     */
    public function externalDocs(): ?\haddowg\JsonApi\OpenApi\ExternalDocumentation;

    /**
     * Every JSON:API type registered for this server, in a stable order.
     *
     * @return list<TypeMetadataInterface>
     */
    public function types(): array;
}
