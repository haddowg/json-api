<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * Core's own described errors, discovered from the directory they live in.
 *
 * The scan is scoped by construction: one flat directory, this file's own, mapped to
 * FQCNs off core's PSR-4 prefix. Nothing outside `src/Exception` can reach the catalogue
 * through this source, and there is no second copy of the membership to drift from it.
 *
 * Discovery runs at most once per process, on first use. The catalogue is read while
 * projecting a document — a warmed or exported artefact, not a per-request path — so
 * reading one directory costs nothing where it actually happens.
 */
final class CoreErrorSource implements ErrorCatalogSourceInterface
{
    /** @var list<class-string<DescribedErrorInterface>>|null */
    private static ?array $classes = null;

    /**
     * @return list<class-string<DescribedErrorInterface>>
     */
    public function describedErrors(): iterable
    {
        return self::$classes ??= self::discover();
    }

    /**
     * Every concrete {@see DescribedErrorInterface} beside this file, in class-name order.
     *
     * The order is case-insensitive so a longer name is not split by a shorter sibling
     * (`ResourceIdentifierIdInvalid` sorts with its family, not after
     * `ResourceIdUndecodable`), and it fixes the order the OpenAPI projection emits the
     * error components in.
     *
     * @return list<class-string<DescribedErrorInterface>>
     */
    private static function discover(): array
    {
        // scandir, not glob: glob() does not traverse stream wrappers and returns an
        // empty array under phar://, which would silently empty the catalogue.
        $entries = \scandir(__DIR__);
        if ($entries === false) {
            return [];
        }

        $classes = [];
        foreach ($entries as $entry) {
            if (!\str_ends_with($entry, '.php')) {
                continue;
            }

            /** @var class-string $class */
            $class = __NAMESPACE__ . '\\' . \substr($entry, 0, -4);
            if (!\is_subclass_of($class, DescribedErrorInterface::class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isAbstract()) {
                continue;
            }

            /** @var class-string<DescribedErrorInterface> $class */
            $classes[] = $class;
        }

        \usort($classes, static fn(string $a, string $b): int => \strcasecmp($a, $b));

        return $classes;
    }
}
