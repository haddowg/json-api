<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so a hook declaration that rejects or narrows an author's
 * closure parameter fails the `composer phpstan` run.
 */
final class HookClosureTypes
{
    /**
     * @return list<FieldBuilderInterface|DeclaredLinkBuilder>
     */
    public static function declarations(): array
    {
        return [
            Str::make('typed')->extractUsing(static fn(\DateTimeImmutable $model): string => $model->format('c')),
            Str::make('array')->extractUsing(static fn(array $row): mixed => $row['title'] ?? null),
            Str::make('documented')->extractUsing(
                /** @param array{title?: string} $row */
                static fn(array $row): ?string => $row['title'] ?? null,
            ),
            Str::make('mixed')->extractUsing(static fn(mixed $model): mixed => \is_array($model) ? $model['title'] ?? null : null),
            Str::make('untyped')->extractUsing(static fn($model, $request, $name) => $name),
            Str::make('nullable')->extractUsing(static fn(?\DateTimeImmutable $model): ?string => $model?->format('c')),
            Str::make('page')->extractUsing(static fn(array $page, JsonApiRequestInterface $request): int => \count($page)),
            Str::make('computedArray')->computedUsing(
                static fn(array $row, JsonApiRequestInterface $request, string $name): mixed => $row[$name] ?? null,
            ),
            Str::make('computedNullable')->computedUsing(static fn(?\DateTimeImmutable $model): ?string => $model?->format('c')),
            Str::make('serialized')->serializeUsing(static fn(string $value): string => \strtoupper($value)),
            Str::make('serializedNullable')->serializeUsing(static fn(?\DateTimeImmutable $value): ?string => $value?->format('c')),
            Str::make('filledArray')->fillUsing(
                static fn(array $row, mixed $value, array $document, string $name): mixed => $row[$name] ?? $value,
            ),
            Str::make('filledTyped')->fillUsing(static fn(\ArrayObject $model, mixed $value): int => $model->count()),
            Str::make('hiddenArray')->hidden(static fn(array $row, JsonApiRequestInterface $request): bool => ($row['secret'] ?? false) === true),
            Str::make('hiddenNullable')->hidden(static fn(?\DateTimeImmutable $model): bool => $model?->getTimestamp() === 0),
            Integer::make('hiddenTyped')->hidden(static fn(\DateTimeImmutable $model): bool => $model->getTimestamp() === 0),
            HasMany::make('items', 'items')
                ->extractUsing(static fn(array $document): mixed => $document['items'] ?? [])
                ->cannotAdd(static fn(array $row): bool => ($row['locked'] ?? false) === true)
                ->cannotRemove(static fn(?\DateTimeImmutable $model): bool => $model?->getTimestamp() === 0)
                ->cannotReplace(static fn(\DateTimeImmutable $model): bool => $model->getTimestamp() === 0)
                ->cannotChangeMembership(static fn(mixed $model): bool => $model === null)
                ->cannotBeIncluded(static fn(array $row): bool => isset($row['private']))
                ->identifierMeta(static fn(array $parent, \DateTimeImmutable $related): array => [
                    'role' => $parent['role'] ?? null,
                    'at' => $related->format('c'),
                ]),
            BelongsTo::make('owner', 'people')->extractUsing(static fn(?\DateTimeImmutable $model): mixed => $model?->getTimezone()),
            MorphTo::make('subject', ['albums', 'artists'])
                ->identifiedBy(static fn(array $row): ?array => isset($row['type'], $row['id']) ? [(string) $row['type'], 1] : null),
            MorphToMany::make('subjects', ['albums', 'artists'])
                ->identifiedBy(static fn(\ArrayObject $model): array => []),
            Obj::make('selected')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'internal')
                ->extractUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? $row : \array_intersect_key($row, \array_flip($fieldset->keys))),
            Obj::make('selectedTyped')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->extractUsing(static fn(\ArrayObject $model): array => $model->getArrayCopy()),
            Obj::make('selectedComputed')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(static fn(string $key): bool => true)
                ->computedUsing(static fn(?\DateTimeImmutable $model, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? [] : $fieldset->keys),
            Obj::make('selectedNested')
                ->fields(Str::make('postcode'), Obj::make('geo')->fields(Integer::make('lat'))->nestedFieldset(pattern: 'lat'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->extractUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => ['paths' => $fieldset?->paths() ?? [], 'geo' => $fieldset?->nested('geo')->keys ?? []]),
            Map::make('selectedMap')
                ->fields(Integer::make('views'))
                ->nestedFieldset(static fn(string $key): bool => $key === 'views')
                ->extractUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $row),
            ArrayList::make('selectedItems')
                ->of(Obj::make('line')->fields(Str::make('sku'), Integer::make('qty'))->nestedFieldset(pattern: 'sku|qty'))
                ->extractUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => ['paths' => $fieldset?->paths() ?? [], 'items' => $fieldset?->items()->keys ?? []]),
            ArrayList::make('selectedItemsComputed')
                ->of(Obj::make('line')->fields(Str::make('sku'))->nestedFieldset(pattern: 'sku'))
                ->computedUsing(static fn(\DateTimeImmutable $model, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => []),
            ArrayList::make('selectedBlocks')
                ->of(
                    OneOf::make('block')->discriminator('type')
                        ->variant('text', Obj::make('text')->fields(Str::make('title'), Str::make('body'))->nestedFieldset(static fn(string $key, JsonApiRequestInterface $request): bool => $key !== 'body'))
                        ->variant('image', Obj::make('image')->fields(Str::make('url'))->nestedFieldset(pattern: 'url')),
                )
                ->extractUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset?->items()->keys ?? []),
            ArrayList::make('scalarItems')->extractUsing(static fn(array $row): array => $row),
            Obj::make('serializedSelection')
                ->fields(Str::make('postcode'), Obj::make('geo')->fields(Integer::make('lat'))->nestedFieldset(pattern: 'lat'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->serializeUsing(static fn(\ArrayObject $model, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? $model->getArrayCopy() : ['geo' => $fieldset->nested('geo')?->keys]),
            Obj::make('serializedUnselected')
                ->additionalProperties(Integer::make('*'))
                ->nestedFieldset(pattern: '[a-z]+')
                ->serializeUsing(static fn(array $row): array => $row),
            Map::make('serializedMap')
                ->fields(Integer::make('views'))
                ->nestedFieldset(static fn(string $key): bool => $key === 'views')
                ->serializeUsing(static fn(?\DateTimeImmutable $model, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset === null ? [] : $fieldset->keys),
            ArrayList::make('serializedItems')
                ->of(Obj::make('line')->fields(Str::make('sku'))->nestedFieldset(pattern: 'sku'))
                ->serializeUsing(static fn(array $row, JsonApiRequestInterface $request, string $name, ?NestedFieldset $fieldset): array => $fieldset?->items()?->paths() ?? $row),
            DeclaredLink::make('download')
                ->hrefUsing(static fn(array $row): ?string => isset($row['path']) ? '/files' : null)
                ->metaUsing(static fn(?\DateTimeImmutable $model): array => ['at' => $model?->format('c')]),
            DeclaredLink::make('upload')
                ->linkUsing(static fn(\DateTimeImmutable $model): string => '/upload/' . $model->format('U')),
        ];
    }
}
