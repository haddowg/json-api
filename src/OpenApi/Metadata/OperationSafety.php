<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * How safe an operation is to call: whether it leaves server state unchanged, whether it
 * may destroy data, and whether repeating it has no further effect. Each flag is `null`
 * when the operation does not declare it.
 *
 * Only an action declares its safety ({@see DeclaresActionSafety}). A CRUD operation's
 * method already says what it does, so its {@see OperationKey} carries none.
 */
final readonly class OperationSafety
{
    public function __construct(
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
    ) {}

    /**
     * The safety the action declares, or `null` when it declares no flag at all.
     */
    public static function of(ActionMetadataInterface $action): ?self
    {
        if (!$action instanceof DeclaresActionSafety) {
            return null;
        }

        $safety = new self($action->readOnly(), $action->destructive(), $action->idempotent());

        return $safety->toArray() === [] ? null : $safety;
    }

    /**
     * The declared flags, keyed `readOnly`, `destructive` and `idempotent`, leaving out
     * every flag that is `null`. This is the projected `x-safety` value.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return \array_filter(
            ['readOnly' => $this->readOnly, 'destructive' => $this->destructive, 'idempotent' => $this->idempotent],
            static fn(?bool $flag): bool => $flag !== null,
        );
    }
}
