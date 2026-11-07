<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The described-error classes you name, each given `$scope` in place of whatever its own
 * descriptor declares. For an error you do not own, such as one a package ships with no
 * scope or one scoped more widely than your server raises it: the catalogue offers it only
 * on the operations `$scope` covers.
 */
final readonly class ScopedErrorSource implements ErrorCatalogSourceInterface
{
    /** @var list<class-string<DescribedErrorInterface>> */
    private array $classes;

    /**
     * @param class-string<DescribedErrorInterface> ...$classes
     */
    public function __construct(public ErrorScope $scope, string ...$classes)
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
