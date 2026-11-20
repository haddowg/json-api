<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;

enum SetFilterGroup: string
{
    case Broadleaf = 'broadleaf';
    case Conifer = 'conifer';
}

final class SetFilterGroupRegistry
{
    /**
     * @param array<mixed> $groups
     *
     * @return list<string>
     */
    public function keysIn(array $groups): array
    {
        return \array_values(\array_filter($groups, \is_string(...)));
    }
}

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so the closure a set filter's deserializeUsing() takes keeps
 * accepting the shapes an author writes, and each ignored error in refused() marks a
 * closure it must not accept.
 */
final class SetFilterDeserializerTypes
{
    /**
     * @return list<FilterBuilderInterface>
     */
    public static function typed(SetFilterGroupRegistry $registry): array
    {
        return [
            WhereIn::make('group', 'type')->enum(SetFilterGroup::class)->acceptsList()->deserializeUsing(static fn(array $groups): array => $registry->keysIn($groups)),
            WhereNotIn::make('notGroup', 'type')->acceptsList()->deserializeUsing(static fn(array $groups): array => $registry->keysIn($groups)),
            WhereIn::make('codes', 'code')->deserializeUsing(
                /**
                 * @param list<string> $codes
                 *
                 * @return list<string>
                 */
                static fn(array $codes): array => \array_map(\strtoupper(...), $codes),
            ),
            WhereIn::make('ids', 'id')->deserializeUsing(static fn(array $ids): array => []),
        ];
    }

    /**
     * @return list<FilterBuilderInterface>
     */
    public static function refused(): array
    {
        return [
            // @phpstan-ignore argument.type (the closure receives the whole list, not one member)
            WhereIn::make('member', 'type')->deserializeUsing(static fn(string $group): array => [$group]),
            // @phpstan-ignore argument.type (the compared values are a list)
            WhereNotIn::make('keyed', 'type')->deserializeUsing(static fn(array $groups): array => ['a' => 'b']),
            // @phpstan-ignore argument.type (the closure returns the compared list, not one value)
            WhereIn::make('scalar', 'type')->deserializeUsing(static fn(array $groups): string => 'a'),
        ];
    }
}
