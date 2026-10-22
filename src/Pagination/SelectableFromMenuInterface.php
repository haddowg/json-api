<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

/**
 * A strategy whose page links can carry the `page[kind]` that selected it from a
 * {@see MultiPaginator} menu.
 *
 * A menu selects a strategy by `page[kind]`, or by a key no other strategy reads, else
 * it uses its default. A link made only of keys several strategies share (`page[size]`,
 * `page[number]`) would therefore fall back to the default. The menu calls
 * {@see selectedFromMenu()} on a strategy it picks over its default, and that copy puts
 * `page[kind]` into every link it renders. A custom strategy implements this to get the
 * same behaviour.
 */
interface SelectableFromMenuInterface extends PaginatorInterface
{
    public function selectedFromMenu(): static;
}
