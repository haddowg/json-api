<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * A date/time value's timezone must be one of an allowed set of IANA timezone
 * identifiers (e.g. `Europe/London`). Adapter-executed; not round-tripped to
 * JSON Schema.
 */
final readonly class Timezone implements Constraint
{
    /**
     * @var list<string>
     */
    public array $allowed;

    /**
     * @param list<string> $allowed
     */
    public function __construct(
        array $allowed,
        public Context $context = new Context(),
    ) {
        $this->allowed = $allowed;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
