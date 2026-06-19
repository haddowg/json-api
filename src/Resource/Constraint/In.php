<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Value must be one of an enumerated set (JSON Schema `enum`).
 *
 * The {@see $values} are always **plain backing scalars** — when the set was
 * sourced from a backed enum (via `->enum(Status::class)` or `->in([...cases])`),
 * each case is normalized to its backing value, so every existing consumer (the
 * JSON Schema compiler, the framework validator bridge, in-memory filters) is
 * unchanged. The enum **class-string** is retained separately in {@see $enumClass}
 * so the OpenAPI projector can emit richer enum metadata (var-names, per-value
 * descriptions, a reusable named component).
 *
 * @template T
 */
final readonly class In implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    /**
     * @var list<T>
     */
    public array $values;

    /**
     * @param list<T>                       $values    the allowed values, as plain backing scalars
     * @param class-string<\BackedEnum>|null $enumClass the backed-enum class the values were sourced from, or `null`
     */
    public function __construct(
        array $values,
        public Context $context = new Context(),
        public ?string $enumClass = null,
    ) {
        $this->values = $values;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
