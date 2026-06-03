<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

/**
 * Convenience base for {@see SerializerInterface} implementations. It `use`s the
 * {@see TransformerTrait} for the attribute-value formatting helpers (date/decimal)
 * and nothing else; the contract is otherwise stateless, so there is nothing
 * per-pass to manage. If you implement {@see SerializerInterface} directly, you can
 * compose {@see TransformerTrait} yourself instead of extending this base.
 */
abstract class AbstractSerializer implements SerializerInterface
{
    use TransformerTrait;
}
