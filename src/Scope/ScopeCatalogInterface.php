<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

/**
 * Every scope the application defines, with a description of what it grants.
 */
interface ScopeCatalogInterface
{
    /**
     * @return array<string, string> scope → description
     */
    public function all(): array;
}
