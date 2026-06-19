<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Enum;

/**
 * Declares a human-readable description for a single enum case, read by the
 * OpenAPI {@see \haddowg\JsonApi\OpenApi\SchemaProjector} when projecting an
 * `enum` schema sourced from a backed enum.
 *
 * Attach it to the case constant of an enum that opts into description support
 * via {@see DescribedEnum} / {@see DescribesEnumCases}:
 *
 * ```php
 * enum Status: string implements DescribedEnum
 * {
 *     use DescribesEnumCases;
 *
 *     #[EnumCaseDescription('Not yet visible to readers')] case Draft = 'draft';
 *     #[EnumCaseDescription('Live and public')]            case Published = 'published';
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final readonly class EnumCaseDescription
{
    public function __construct(public string $description) {}
}
