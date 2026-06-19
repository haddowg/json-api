<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Enum;

/**
 * Default {@see DescribedEnum} implementation: reads the
 * {@see EnumCaseDescription} attribute off each case constant by reflection.
 *
 * Compose it onto a backed enum that implements {@see DescribedEnum}:
 *
 * ```php
 * enum Status: string implements DescribedEnum
 * {
 *     use DescribesEnumCases;
 *
 *     #[EnumCaseDescription('Not yet visible to readers')] case Draft = 'draft';
 * }
 * ```
 *
 * `$case->name` already yields the variable name (the `x-enum-varnames` source),
 * so only the human-readable description needs declaring.
 *
 * @phpstan-require-implements DescribedEnum
 */
trait DescribesEnumCases
{
    public function description(): ?string
    {
        if (!$this instanceof \UnitEnum) {
            return null;
        }

        return self::reflectCaseDescription(new \ReflectionEnum($this), $this->name);
    }

    /**
     * Maps every case's **backing value** to its {@see EnumCaseDescription},
     * skipping cases that carry none — the form the OpenAPI projector consumes
     * (`x-enum-descriptions` aligned to the enum's backing values).
     *
     * @return array<int|string, string>
     */
    public static function descriptions(): array
    {
        $reflection = new \ReflectionEnum(static::class);
        if (!$reflection->isBacked()) {
            return [];
        }

        $descriptions = [];
        foreach ($reflection->getCases() as $case) {
            if (!$case instanceof \ReflectionEnumBackedCase) {
                continue;
            }

            $description = self::reflectCaseDescription($reflection, $case->getName());
            if ($description !== null) {
                $descriptions[$case->getBackingValue()] = $description;
            }
        }

        return $descriptions;
    }

    /**
     * Reads the {@see EnumCaseDescription} declared on the named case, or `null`
     * when the case is absent or carries none.
     *
     * @template T of \UnitEnum
     *
     * @param \ReflectionEnum<T> $reflection
     */
    private static function reflectCaseDescription(\ReflectionEnum $reflection, string $caseName): ?string
    {
        if (!$reflection->hasCase($caseName)) {
            return null;
        }

        $attributes = $reflection->getCase($caseName)->getAttributes(EnumCaseDescription::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->description;
    }
}
