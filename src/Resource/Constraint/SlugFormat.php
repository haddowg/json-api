<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a URL slug. Compiles to a JSON Schema `pattern`; the default
 * pattern is lowercase alphanumerics separated by single hyphens.
 */
final readonly class SlugFormat implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface
{
    public const string DEFAULT_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    public function __construct(
        public string $regex = self::DEFAULT_PATTERN,
        public Context $context = new Context(),
    ) {}

    public function context(): Context
    {
        return $this->context;
    }
}
