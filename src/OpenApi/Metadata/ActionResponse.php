<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker: one success response a custom action ({@see ActionMetadataInterface::responds()})
 * may advertise in the projected OpenAPI document. Implemented by the atomic response
 * carriers valid for an action — {@see ActionResource} (`200` + a named resource
 * document), {@see MetaResult} (`200` meta-only), {@see NoContent} (`204`),
 * {@see Accepted} (`202` async accept) and {@see SeeOther} (`303` completion redirect).
 *
 * It generalises the former `ActionOutputMode`/`outputType` pair (and the integrations'
 * `returns204`/`outputMeta` flags) into the same declarative response vocabulary the
 * CRUD/read operations use, so an action can advertise the full async lifecycle.
 */
interface ActionResponse extends OperationResponseInterface {}
