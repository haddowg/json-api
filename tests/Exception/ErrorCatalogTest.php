<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception;

use haddowg\JsonApi\Exception\AtomicOperationsInvalid;
use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\CoreErrorSource;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\FieldsetMemberUnrecognized;
use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\PaginationKindUnknown;
use haddowg\JsonApi\Exception\RelatedAttributeOwnerMissing;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\ResourceIdConflict;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\Exception\Fixture\RivalResourceNotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is assembled from sources, so these are the checks that keep the assembly
 * honest: core discovers all of its own and nothing else, a contribution joins them, a
 * class named twice is catalogued once, two classes claiming one code are refused, and a
 * descriptor still agrees with the error its exception renders.
 */
#[CoversClass(ErrorCatalog::class)]
#[CoversClass(CoreErrorSource::class)]
#[CoversClass(ClassListErrorSource::class)]
#[CoversClass(ErrorDescriptor::class)]
#[Group('spec:errors')]
final class ErrorCatalogTest extends TestCase
{
    #[Test]
    public function coreDiscoversEveryDescribedErrorItShips(): void
    {
        $discovered = [...(new CoreErrorSource())->describedErrors()];

        self::assertSame($this->describedErrorFilesInCore(), $this->sorted($discovered));
    }

    #[Test]
    public function coreDiscoversNothingOutsideItsOwnExceptionDirectory(): void
    {
        foreach ((new CoreErrorSource())->describedErrors() as $class) {
            self::assertStringStartsWith('haddowg\\JsonApi\\Exception\\', $class);
            self::assertFileExists(\dirname(__DIR__, 2) . '/src/Exception/' . $this->shortName($class) . '.php');
        }
    }

    #[Test]
    public function aContributedSourceJoinsCoresCodes(): void
    {
        $catalog = new ErrorCatalog(new CoreErrorSource(), new ClassListErrorSource(PaymentRequired::class));

        self::assertContains(PaymentRequired::class, $catalog->exceptions());
        self::assertContains(ResourceNotFound::class, $catalog->exceptions());

        $codes = \array_map(
            static fn(ErrorDescriptor $descriptor): string => $descriptor->code,
            $catalog->descriptors(),
        );
        self::assertContains('PAYMENT_REQUIRED', $codes);
    }

    #[Test]
    public function aClassTwoSourcesBothNameIsCataloguedOnce(): void
    {
        $catalog = new ErrorCatalog(
            new ClassListErrorSource(PaymentRequired::class),
            new ClassListErrorSource(PaymentRequired::class),
        );

        self::assertSame([PaymentRequired::class], $catalog->exceptions());
    }

    #[Test]
    public function twoClassesClaimingOneCodeAreRefusedByName(): void
    {
        $catalog = new ErrorCatalog(new CoreErrorSource(), new ClassListErrorSource(RivalResourceNotFound::class));

        try {
            $catalog->exceptions();
        } catch (\LogicException $refused) {
            // Both claimants by name: a code collision is a wiring bug, and the message
            // has to say which two classes to go and look at.
            self::assertStringContainsString('RESOURCE_NOT_FOUND', $refused->getMessage());
            self::assertStringContainsString(ResourceNotFound::class, $refused->getMessage());
            self::assertStringContainsString(RivalResourceNotFound::class, $refused->getMessage());

            return;
        }

        self::fail('a second class claiming RESOURCE_NOT_FOUND was catalogued silently');
    }

    #[Test]
    public function noTwoOfCoresExceptionsClaimTheSameCode(): void
    {
        $codes = \array_map(
            static fn(ErrorDescriptor $descriptor): string => $descriptor->code,
            ErrorCatalog::core()->descriptors(),
        );

        self::assertSame(\array_unique($codes), $codes, 'Two exceptions share an error code.');
    }

    #[Test]
    public function everyCodeCoreShipsTellsAConsumerWhatItMeans(): void
    {
        $undescribed = [];
        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            if ($descriptor->description === null || \trim($descriptor->description) === '') {
                $undescribed[] = $descriptor->code;
            }
        }

        self::assertSame([], $undescribed, 'These codes need a consumer-facing description on their descriptor.');
    }

    /**
     * An example detail is published as what a client will read, so it must be one the
     * exception actually renders, not a paraphrase that drifts from the template.
     */
    #[Test]
    #[DataProvider('exceptionsRenderingTheirExampleDetail')]
    public function anExampleDetailIsOneTheExceptionRenders(DescribedErrorInterface $exception): void
    {
        $example = $exception::describe()->exampleDetail;

        self::assertNotNull($example);
        self::assertSame($example, $exception->getErrors()[0]->detail);
    }

    /**
     * @return iterable<string, array{DescribedErrorInterface}>
     */
    public static function exceptionsRenderingTheirExampleDetail(): iterable
    {
        yield 'ATOMIC_OPERATIONS_INVALID' => [new AtomicOperationsInvalid("Unknown atomic operation code 'upsert'.", '/atomic:operations/0/op')];
        yield 'ATTRIBUTE_VALUE_INVALID' => [new AttributeValueInvalid('publishedAt', 'Failed to parse time string (next blursday) at position 0 (n): The timezone could not be found in the database')];
        yield 'FIELDSET_MEMBER_UNRECOGNIZED' => [new FieldsetMemberUnrecognized('articles', ['colour'])];
        yield 'FILTERING_UNRECOGNIZED' => [new FilterParamUnrecognized('colour')];
        yield 'INCLUSION_DEPTH_EXCEEDED' => [new InclusionDepthExceeded(['author.publisher.country'], 2)];
        yield 'INCLUSION_UNRECOGNIZED' => [new InclusionUnrecognized(['auther'])];
        yield 'MEDIA_TYPE_UNACCEPTABLE' => [new MediaTypeUnacceptable('application/vnd.api+json; version=2')];
        yield 'MEDIA_TYPE_UNSUPPORTED' => [new MediaTypeUnsupported('application/vnd.api+json; charset=utf-8')];
        yield 'PAGINATION_KIND_UNKNOWN' => [new PaginationKindUnknown('offset', ['page', 'cursor'])];
        yield 'RELATED_ATTRIBUTE_OWNER_MISSING' => [new RelatedAttributeOwnerMissing('city', 'address')];
        yield 'RELATIONSHIP_TYPE_INAPPROPRIATE' => [new RelationshipTypeInappropriate('author', 'to-many', 'to-one')];
        yield 'RESOURCE_ID_CONFLICT' => [new ResourceIdConflict('12', '7')];
        yield 'SORTING_UNRECOGNIZED' => [new SortParamUnrecognized('popularity')];
    }

    #[Test]
    public function everyCodeWithAnExampleDetailIsCheckedAgainstItsException(): void
    {
        $exemplified = [];
        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            if ($descriptor->exampleDetail !== null) {
                $exemplified[] = $descriptor->code;
            }
        }
        \sort($exemplified);

        $checked = \array_keys(\iterator_to_array(self::exceptionsRenderingTheirExampleDetail()));
        \sort($checked);

        self::assertSame($exemplified, $checked, 'Add the exception to exceptionsRenderingTheirExampleDetail().');
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
     * Every concrete described-error class filed under `src/Exception`, read straight off
     * the directory. This is the same guarantee the old hand-written roster gave, without
     * a second copy of the membership to fall out of date.
     *
     * @return list<class-string<DescribedErrorInterface>>
     */
    private function describedErrorFilesInCore(): array
    {
        $files = \glob(\dirname(__DIR__, 2) . '/src/Exception/*.php');
        self::assertIsArray($files);

        $classes = [];
        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'haddowg\\JsonApi\\Exception\\' . \basename($file, '.php');
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

        self::assertNotEmpty($classes);

        return $this->sorted($classes);
    }

    /**
     * @param list<class-string<DescribedErrorInterface>> $classes
     * @return list<class-string<DescribedErrorInterface>>
     */
    private function sorted(array $classes): array
    {
        \sort($classes);

        return $classes;
    }

    /**
     * @param class-string $class
     */
    private function shortName(string $class): string
    {
        return (new \ReflectionClass($class))->getShortName();
    }
}
