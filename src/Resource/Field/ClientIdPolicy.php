<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Whether a create may carry a client-supplied `data.id`, set on the {@see Id}
 * field by {@see Id::allowClientId()} / {@see Id::requireClientId()}.
 *
 * @internal
 */
enum ClientIdPolicy
{
    /**
     * A client-supplied id is rejected with `ClientGeneratedIdNotSupported` (the
     * default — the spec lets a server forbid client ids).
     */
    case Forbidden;

    /**
     * A client-supplied id is accepted when present, generated otherwise.
     */
    case Optional;

    /**
     * A client-supplied id is mandatory; its absence yields
     * `ClientGeneratedIdRequired`.
     */
    case Required;
}
