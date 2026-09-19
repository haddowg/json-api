<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The described-error classes you name, in the order you name them.
 *
 * The escape hatch for a class no scan reaches: one generated into a cache directory,
 * one living outside the paths an integration is configured to look at, or a single
 * exception an application wants catalogued without any discovery at all.
 */
final readonly class ClassListErrorSource implements ErrorCatalogSourceInterface
{
    /** @var list<class-string<DescribedErrorInterface>> */
    private array $classes;

    /**
     * @param class-string<DescribedErrorInterface> ...$classes
     */
    public function __construct(string ...$classes)
    {
        $this->classes = \array_values($classes);
    }

    /**
     * @return list<class-string<DescribedErrorInterface>>
     */
    public function describedErrors(): iterable
    {
        return $this->classes;
    }
}
