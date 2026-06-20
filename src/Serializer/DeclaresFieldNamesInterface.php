<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * An opt-in capability a {@see SerializerInterface} MAY implement to expose its
 * **declared field namespace** — every field name this resource type recognizes,
 * for strict `fields[type]` sparse-fieldset member validation.
 *
 * The {@see \haddowg\JsonApi\Server\Server} reads it via
 * `instanceof DeclaresFieldNamesInterface` (it is NOT part of
 * {@see SerializerInterface}, so a serializer that does not implement it — a
 * standalone bare serializer with no field inventory — is tolerated: its
 * `fields[type]` members are never validated, exactly today's behaviour). This
 * mirrors how the transformer reads {@see IncludeControlsInterface}.
 *
 * The declared namespace is **request-independent**: it is the full inventory of
 * declared field names — attributes AND relationships, INCLUDING hidden,
 * write-only, conditionally-hidden and non-sparse fields, and `id`. A
 * `fields[type]` member is therefore "unknown" only when it names no declared
 * field at all (a real typo); a hidden field name and a bogus name behave
 * identically (both tolerated when hidden, since the namespace is unfiltered) so
 * there is no information leak.
 *
 * {@see \haddowg\JsonApi\Resource\AbstractResource} implements this from its
 * field inventory, so every Resource subclass satisfies the interface
 * automatically.
 */
interface DeclaresFieldNamesInterface
{
    /**
     * Every declared field name for this resource type — the full member
     * namespace (attributes + relationships, including hidden/write-only/
     * conditionally-hidden/non-sparse fields and `id`), request-independent.
     *
     * @return list<string>
     */
    public function declaredFieldNames(): array;
}
