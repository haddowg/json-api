<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * The OpenAPI-relevant metadata for one custom action (a `#[AsJsonApiAction]`),
 * consumed by the Slice-3 path projection (each action → a {@see
 * \haddowg\JsonApi\OpenApi\PathItem} under the `-actions` segment). Defined now so
 * the contract is stable; this slice's component projection does not read it.
 */
interface ActionMetadataInterface
{
    /**
     * The action's path segment under `-actions` (e.g. `publish`).
     */
    public function path(): string;

    /**
     * The HTTP methods the action responds to (upper-case, e.g. `['POST']`).
     *
     * @return list<string>
     */
    public function methods(): array;

    /**
     * Whether the action is mounted on the collection or an individual resource.
     */
    public function scope(): ActionScope;

    /**
     * How the action consumes its request body.
     */
    public function inputMode(): ActionInputMode;

    /**
     * The JSON:API type whose request schema is the action's body, when
     * {@see inputMode()} is {@see ActionInputMode::Document}; `null` otherwise.
     */
    public function inputType(): ?string;

    /**
     * The JSON:API type whose document schema is the action's success response;
     * `null` when the action returns `204 No Content`.
     */
    public function outputType(): ?string;

    /**
     * Whether the action carries a security expression — i.e. it should be emitted
     * with the configured security requirement (the expression itself is never
     * parsed for scheme semantics).
     */
    public function isSecured(): bool;

    /**
     * The OpenAPI tag names this action's operation is grouped under.
     *
     * @return list<string>
     */
    public function tags(): array;

    /**
     * A short human-readable summary for the action's operation, or `null`.
     */
    public function summary(): ?string;

    /**
     * A longer description for the action's operation, or `null`.
     */
    public function description(): ?string;
}
