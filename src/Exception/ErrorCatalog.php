<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The error codes a server documents, assembled from its {@see ErrorCatalogSourceInterface}s.
 *
 * Core's codes are one source ({@see CoreErrorSource}); an application's or a framework
 * integration's are others. The catalogue merges them in source order, drops a class two
 * sources both name, and refuses a code two *different* classes claim — a `code` is the
 * identifier a client dispatches on, so last-one-wins would publish one class's status
 * and title under another class's code.
 *
 * The exceptions stay the source of truth: a descriptor is read off the class with
 * {@see DescribedErrorInterface::describe()}, never by constructing an exception with
 * invented arguments, because `getErrors()` interpolates constructor state and the result
 * would be fiction.
 *
 * The catalogue is never authoritative over the whole world — an application can always
 * throw a code no source declared — which is why the projected `anyOf` keeps an open
 * generic `Error` branch alongside the named variants
 * ([ADR 0136](../../docs/adr/0136-the-projected-error-code-catalogue-is-open.md)).
 */
final class ErrorCatalog
{
    /** @var list<ErrorCatalogSourceInterface> */
    private readonly array $sources;

    /** @var list<class-string<DescribedErrorInterface>>|null */
    private ?array $exceptions = null;

    public function __construct(ErrorCatalogSourceInterface ...$sources)
    {
        $this->sources = \array_values($sources);
    }

    /**
     * Core's codes and nothing else.
     */
    public static function core(): self
    {
        return new self(new CoreErrorSource());
    }

    /**
     * Every described-error class the sources contribute, deduplicated, in source order.
     *
     * @return list<class-string<DescribedErrorInterface>>
     */
    public function exceptions(): array
    {
        return $this->exceptions ??= $this->merge();
    }

    /**
     * The descriptors themselves, in the same order.
     *
     * @return list<ErrorDescriptor>
     */
    public function descriptors(): array
    {
        return \array_map(
            static fn(string $exception): ErrorDescriptor => $exception::describe(),
            $this->exceptions(),
        );
    }

    /**
     * @return list<class-string<DescribedErrorInterface>>
     */
    private function merge(): array
    {
        /** @var array<class-string<DescribedErrorInterface>, true> $classes */
        $classes = [];
        /** @var array<string, class-string<DescribedErrorInterface>> $claimants */
        $claimants = [];

        foreach ($this->sources as $source) {
            foreach ($source->describedErrors() as $class) {
                if (isset($classes[$class])) {
                    continue;
                }

                $code = $class::describe()->code;
                if (isset($claimants[$code])) {
                    throw new \LogicException(\sprintf(
                        'The error code "%s" is claimed by both %s and %s. An error code identifies one error to a client, so rename one of them.',
                        $code,
                        $claimants[$code],
                        $class,
                    ));
                }

                $claimants[$code] = $class;
                $classes[$class] = true;
            }
        }

        return \array_keys($classes);
    }
}
