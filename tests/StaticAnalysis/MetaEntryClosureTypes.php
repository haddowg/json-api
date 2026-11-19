<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\AdditionalPropertiesBuilder;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so an `additionalProperties` entry's hook declaration that
 * rejects or narrows an author's closure fails the `composer phpstan` run.
 */
final class MetaEntryClosureTypes
{
    /**
     * @return list<AdditionalPropertiesBuilder|FieldBuilderInterface>
     */
    public static function declarations(): array
    {
        return [
            AdditionalProperties::make()
                ->of(Str::make('*'))
                ->extractUsing(static fn(\ArrayObject $inbox, JsonApiRequestInterface $request): array => $inbox->getArrayCopy()),
            AdditionalProperties::make()
                ->extractUsing(static fn(array $row): array => \is_array($row['labels'] ?? null) ? $row['labels'] : []),
            AdditionalProperties::make()
                ->extractUsing(static fn(?\DateTimeImmutable $model, JsonApiRequestInterface $request, string $name): array => [$name => $model?->format('c')]),
            AdditionalProperties::make()
                ->of(Integer::make('*'))
                ->fillUsing(static function (\ArrayObject $inbox, array $members): void {
                    foreach ($members as $name => $value) {
                        $inbox[$name] = $value;
                    }
                }),
            AdditionalProperties::make()
                ->fillUsing(static fn(array $row, array $members, array $written, string $name): array => [...$row, $name => $members]),
            AdditionalProperties::make()
                ->fillUsing(static fn(mixed $model, array $members): mixed => $model),
            BelongsToMany::make('rooms', 'rooms')->fields(
                Integer::make('nights'),
                AdditionalProperties::make()->extractUsing(static fn(PivotEdge $edge): array => $edge->pivot),
            ),
        ];
    }
}
