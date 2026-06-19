<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Enum;

/**
 * Opt-in marker + contract for a backed enum whose cases carry
 * {@see EnumCaseDescription} metadata. Implement it (composing
 * {@see DescribesEnumCases} for the default reflection-based implementation) so
 * the OpenAPI {@see \haddowg\JsonApi\OpenApi\SchemaProjector} emits per-value
 * descriptions for an `enum` schema sourced from this enum.
 *
 * The instance method returns the description of the current case; the static
 * {@see DescribesEnumCases::descriptions()} companion maps every case's backing
 * value to its description (the form the projector consumes).
 */
interface DescribedEnum
{
    /**
     * The description declared on this case via {@see EnumCaseDescription}, or
     * `null` when the case carries none.
     */
    public function description(): ?string;

    /**
     * Maps every case's **backing value** to its {@see EnumCaseDescription} (cases
     * without one omitted) — the form the OpenAPI projector consumes. Provided by
     * {@see DescribesEnumCases}.
     *
     * @return array<int|string, string>
     */
    public static function descriptions(): array;
}
