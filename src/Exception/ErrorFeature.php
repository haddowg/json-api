<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The server capability an error depends on: without it, the error cannot be raised.
 *
 * An {@see ErrorDescriptor} names one (or `null` for an error any JSON:API server can
 * raise), and the OpenAPI projection drops the described code from a document whose
 * server does not offer the capability — the same registration-awareness that already
 * gates `?withCount`, the cursor `x-profile` marker and the write components
 * ([ADR 0131](../../docs/adr/0131-registration-aware-openapi-projection.md)). A client
 * generated from a read-only server has no use for a typed
 * `RESOURCE_TYPE_UNACCEPTABLE`.
 *
 * Each case states the condition it tests, because an over-eager gate hides an error a
 * client will actually meet.
 */
enum ErrorFeature: string
{
    /**
     * The server registered the Atomic Operations extension. Local-id resolution and
     * the `atomic:operations` document only exist inside a batch.
     */
    case AtomicOperations = 'atomic-operations';

    /**
     * At least one collection paginates by cursor, so a `page[after]` / `page[before]`
     * token can be sent and therefore rejected.
     */
    case CursorPagination = 'cursor-pagination';

    /**
     * At least one collection offers a {@see \haddowg\JsonApi\Pagination\MultiPaginator}
     * menu, so `page[kind]` can name a strategy the menu does not hold.
     */
    case PaginationMenu = 'pagination-menu';

    /**
     * The Countable profile is registered, so `?withCount` is honoured and can name an
     * uncountable relationship.
     */
    case RelationshipCounts = 'relationship-counts';

    /**
     * At least one type permits a client-generated id, so an id can arrive in a create
     * body and collide, and a type can insist on one.
     */
    case ClientGeneratedIds = 'client-generated-ids';

    /**
     * The server exposes at least one write (create, update, delete, or an atomic
     * batch). Everything that parses or hydrates a request document hangs off this.
     */
    case Writes = 'writes';
}
