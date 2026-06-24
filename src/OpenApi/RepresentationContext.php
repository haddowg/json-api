<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * The representation a set of attributes is projected for. A field's visibility
 * and the `required` set differ by context, so the three cannot share one schema:
 *
 * - {@see Read} — a **response** body. Excludes write-only fields (they are never
 *   returned), includes read-only fields. Nothing is `required` in the request
 *   sense.
 * - {@see Create} — a **POST** request body. Excludes fields read-only on create,
 *   includes write-only fields. `required` carries the create-context required set.
 * - {@see Update} — a **PATCH** request body. Excludes fields read-only on update,
 *   includes write-only fields. Nothing is `required` — a PATCH is partial, so an
 *   absent member means "no change" (a supplied member is still value-constrained).
 */
enum RepresentationContext
{
    case Read;
    case Create;
    case Update;
}
