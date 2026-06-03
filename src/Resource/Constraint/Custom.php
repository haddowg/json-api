<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Opaque escape-hatch constraint for rules the core does not model. Consumers
 * and adapter packages register a handler keyed by `$id` and read `$payload`.
 *
 * **Not round-tripped to JSON Schema** — the {@see \haddowg\JsonApi\Validation\SchemaCompiler}
 * skips `Custom` (the rule is adapter-specific).
 */
final readonly class Custom implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public function __construct(
        public string $id,
        public mixed $payload = null,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
