<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

/**
 * The security rule an integration attaches to one operation: its expression, as the
 * integration declares it, and what the rule needs to decide.
 */
final readonly class OperationSecurity
{
    /**
     * @param string|bool|null $expression the rule as declared: an expression, `true` or `false`, or `null` for none
     */
    public function __construct(
        public string|bool|null $expression = null,
        public SecurityLevel $level = SecurityLevel::None,
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
