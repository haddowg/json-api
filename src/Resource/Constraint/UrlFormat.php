<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * String must be a valid URL (JSON Schema `format: uri`). Optionally restricts
 * the allowed URI schemes (e.g. `https`); an empty list allows any scheme.
 */
final readonly class UrlFormat implements Constraint
{
    /**
     * @var list<string>
     */
    public array $allowedSchemes;

    /**
     * @param list<string> $allowedSchemes
     */
    public function __construct(
        array $allowedSchemes = [],
        public Context $context = new Context(),
    ) {
        $this->allowedSchemes = $allowedSchemes;
    }

    public function context(): Context
    {
        return $this->context;
    }
}
