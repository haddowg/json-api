<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * An optional extension of {@see ActionMetadataInterface} for an action that replaces or
 * switches off the examples the projector generates for its operation.
 */
interface DeclaresActionExample
{
    /**
     * `null` keeps the generated example, `false` emits none, and an
     * {@see OperationExample} replaces the parts it names.
     */
    public function example(): OperationExample|false|null;
}
