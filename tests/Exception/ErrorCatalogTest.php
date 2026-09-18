<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is a hand-written roster, which is the only honest way to enumerate
 * classes as data — so these are the checks that keep it honest: nothing missing,
 * nothing duplicated, and a descriptor that still agrees with the error the exception
 * renders.
 */
#[CoversClass(ErrorCatalog::class)]
#[CoversClass(ErrorDescriptor::class)]
#[Group('spec:errors')]
final class ErrorCatalogTest extends TestCase
{
    /**
     * The directory scan lives here rather than in the catalogue: production code
     * enumerating itself off the filesystem would be a trick, but a test asserting the
     * roster is complete is the guard that makes the roster trustworthy.
     */
    #[Test]
    public function everyExceptionCoreShipsIsCatalogued(): void
    {
        $catalogued = ErrorCatalog::exceptions();

        foreach ($this->concreteExceptions() as $exception) {
            self::assertContains(
                $exception,
                $catalogued,
                $exception . ' throws a JSON:API error but is not in ErrorCatalog::exceptions().',
            );
        }

        self::assertCount(\count($this->concreteExceptions()), $catalogued);
    }

    #[Test]
    public function everyCatalogedExceptionDescribesItself(): void
    {
        foreach (ErrorCatalog::exceptions() as $exception) {
            self::assertTrue(
                \is_subclass_of($exception, DescribedErrorInterface::class),
                $exception . ' is catalogued but does not implement DescribedErrorInterface.',
            );
        }
    }

    #[Test]
    public function noTwoExceptionsClaimTheSameCode(): void
    {
        $codes = \array_map(
            static fn(ErrorDescriptor $descriptor): string => $descriptor->code,
            ErrorCatalog::descriptors(),
        );

        self::assertSame(\array_unique($codes), $codes, 'Two exceptions share an error code.');
    }

    #[Test]
    public function aDescriptorMatchesTheErrorItsExceptionRenders(): void
    {
        $descriptor = FilterParamUnrecognized::describe();
        $exception = new FilterParamUnrecognized('colour');
        $error = $exception->getErrors()[0];

        self::assertSame($descriptor->code, $error->code);
        self::assertSame((string) $descriptor->status, $error->status);
        self::assertSame($descriptor->title, $error->title);
        self::assertSame($descriptor->status, $exception->getStatusCode());
    }

    #[Test]
    public function aDeclaredContextShapeNamesTheKeysTheErrorActuallyCarries(): void
    {
        $descriptor = InclusionDepthExceeded::describe();
        $error = (new InclusionDepthExceeded(['a.b.c'], 2))->getErrors()[0];

        self::assertSame(\array_keys($descriptor->context), \array_keys($error->context));
    }

    /**
     * Every concrete exception class under `src/Exception`.
     *
     * @return list<class-string<JsonApiExceptionInterface>>
     */
    private function concreteExceptions(): array
    {
        $files = \glob(\dirname(__DIR__, 2) . '/src/Exception/*.php');
        self::assertIsArray($files);

        $exceptions = [];
        foreach ($files as $file) {
            $class = 'haddowg\\JsonApi\\Exception\\' . \basename($file, '.php');
            if (!\class_exists($class) || !\is_subclass_of($class, JsonApiExceptionInterface::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $exceptions[] = $class;
        }

        self::assertNotEmpty($exceptions);

        return $exceptions;
    }
}
