<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Transformer\TransformerTrait;

/**
 * Convenience base for {@see SerializerInterface} implementations. Adds the
 * attribute-value {@see TransformerTrait} helpers (date/decimal formatting); the
 * contract is otherwise stateless, so there is nothing per-pass to manage.
 */
abstract class AbstractSerializer implements SerializerInterface
{
    use TransformerTrait;
}
