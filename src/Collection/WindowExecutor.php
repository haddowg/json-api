<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection;

use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\WindowInterface;

/**
 * The storage-agnostic window/count/count-free "tail" every data layer runs once
 * a collection has been filtered and sorted: it decides, from the requested
 * {@see WindowInterface} and whether the collection is countable, what to fetch
 * and how to shape the {@see CollectionResult}.
 *
 * The store-specific work — materializing the unwindowed collection, counting it,
 * fetching a windowed page, probing one item past a page — is supplied as
 * closures, so this class references only core/PHP types: a Doctrine provider
 * passes `QueryBuilder`-backed closures (push-down `LIMIT`/`OFFSET`/`COUNT`), an
 * in-memory provider passes `array_slice`/`count` closures, and both get the
 * identical branch logic. This is the consolidation of the four hand-rolled tails
 * the bundle's read providers each carried (core ADR 0061); it is also the home
 * the cursor (keyset) window strategy will plug into without touching any
 * provider.
 *
 * The branches, all behaviour-identical to the tails they replace:
 *
 * - **no window** — {@see $all} is returned verbatim, no count;
 * - **a non-{@see OffsetWindow} window** — a `\LogicException` (the only window
 *   shape a count-based store can execute is offset-based; a different window is a
 *   programming error, not a request error);
 * - **countable + window** — {@see $count} gives the pre-window total, {@see $page}
 *   the windowed page, and the result carries both ({@see CollectionResult::$total}
 *   drives `links.last`/`meta.page.total`);
 * - **count-free + window** — no count runs; {@see $probe} over-fetches by one
 *   ({@see OffsetWindow::$limit} `+ 1`), the surplus proves a further page exists,
 *   and the result is windowed with a `null` total and a `hasMore` flag (core ADR
 *   0057). The limit+1 probe is equivalent to the in-memory `count(items) > offset
 *   + count(page)` form: both report "more available beyond this page" without a
 *   total.
 */
final class WindowExecutor
{
    /**
     * Runs the tail for a filtered+sorted collection and returns the shaped
     * {@see CollectionResult}.
     *
     * @template TEntity of object
     *
     * @param ?WindowInterface              $window    the requested fetch window, or null for an unpaginated fetch
     * @param bool                          $countable whether the collection is countable (a primary collection or a
     *                                                 countable relation count; a non-countable related to-many is
     *                                                 false, taking the count-free branch)
     * @param callable():iterable<TEntity>  $all       materializes the whole filtered collection (the no-window branch)
     * @param callable():int                $count     the pre-window total of the filtered collection (the countable branch)
     * @param callable(int,int):iterable<TEntity> $page fetches the windowed page `(offset, limit)` (the countable branch)
     * @param callable(int,int):list<TEntity>     $probe fetches UP TO `limit + 1` items from `(offset, limit + 1)` (the
     *                                                  count-free branch); the caller passes `limit + 1` as the second
     *                                                  argument so a surplus item signals a further page
     *
     * @return CollectionResult<TEntity>
     */
    public function run(
        ?WindowInterface $window,
        bool $countable,
        callable $all,
        callable $count,
        callable $page,
        callable $probe,
    ): CollectionResult {
        // No window: a plain unpaginated collection — the whole filtered set, no count.
        if ($window === null) {
            return new CollectionResult($all());
        }

        // A count-based store can only execute an offset window; any other window
        // shape (e.g. a cursor window handed to a count-based provider) is a wiring
        // error, surfaced as a LogicException naming this executor and the window type.
        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        // Count-free page (a non-countable related to-many, core ADR 0057): no COUNT
        // runs. Probe one item past the window (limit + 1); a surplus item proves a
        // further page exists and is dropped from the rendered items, so the result
        // carries a null total, windowed = true, and hasMore from the surplus.
        if (!$countable) {
            $probed = $probe($window->offset, $window->limit + 1);

            $hasMore = \count($probed) > $window->limit;
            if ($hasMore) {
                $probed = \array_slice($probed, 0, $window->limit);
            }

            return new CollectionResult($probed, total: null, windowed: true, hasMore: $hasMore);
        }

        // Countable page: count the pre-window total, then fetch the windowed page;
        // the total drives the count-based page's links.last / meta.page.total.
        $total = $count();

        return new CollectionResult($page($window->offset, $window->limit), $total, windowed: true);
    }
}
