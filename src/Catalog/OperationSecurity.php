<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

/**
 * The security rule an integration attaches to one operation: its expression, as the
 * integration declares it, what the rule needs to decide, and any other rule the
 * operation also enforces.
 */
final readonly class OperationSecurity
{
    /**
     * @param string|bool|null     $expression      the rule as declared: an expression, `true` or `false`, or `null` for none
     * @param list<AdditionalGate> $additionalGates the other rules the operation enforces, each of which can refuse it on its own
     */
    public function __construct(
        public string|bool|null $expression = null,
        public SecurityLevel $level = SecurityLevel::None,
        public array $additionalGates = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
