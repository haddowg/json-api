<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\CreateResponse;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\Metadata\UpdateResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationResponses::class)]
#[CoversClass(Created::class)]
#[CoversClass(Ok::class)]
#[CoversClass(NoContent::class)]
#[CoversClass(Accepted::class)]
#[CoversClass(SeeOther::class)]
#[CoversClass(MetaResult::class)]
final class OperationResponsesTest extends TestCase
{
    #[Test]
    public function eachResponseCarriesItsStatusAndJobType(): void
    {
        self::assertSame(201, (new Created())->status());
        self::assertNull((new Created())->jobType());

        self::assertSame(200, (new Ok())->status());
        self::assertNull((new Ok())->jobType());

        self::assertSame(204, (new NoContent())->status());
        self::assertNull((new NoContent())->jobType());

        self::assertSame(202, (new Accepted('jobs'))->status());
        self::assertSame('jobs', (new Accepted('jobs'))->jobType());

        self::assertSame(303, (new SeeOther())->status());
        self::assertNull((new SeeOther())->jobType());

        self::assertSame(200, (new MetaResult())->status());
        self::assertNull((new MetaResult())->jobType());
    }

    #[Test]
    public function acceptedRejectsAnEmptyJobType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Accepted('');
    }

    #[Test]
    public function defaultForReturnsTheSingleHistoricStatusPerOperation(): void
    {
        self::assertSame(200, OperationResponses::defaultFor(OperationType::FetchCollection)[0]->status());
        self::assertSame(200, OperationResponses::defaultFor(OperationType::FetchOne)[0]->status());
        self::assertSame(201, OperationResponses::defaultFor(OperationType::Create)[0]->status());
        self::assertSame(200, OperationResponses::defaultFor(OperationType::Update)[0]->status());
        self::assertSame(204, OperationResponses::defaultFor(OperationType::Delete)[0]->status());

        foreach (OperationType::cases() as $operation) {
            self::assertCount(1, OperationResponses::defaultFor($operation));
        }
    }

    #[Test]
    public function validateAcceptsAWellFormedSet(): void
    {
        OperationResponses::validate(OperationType::Create, [new Created(), new Accepted('jobs')]);
        OperationResponses::validate(OperationType::FetchOne, [new Ok(), new SeeOther()]);
        OperationResponses::validate(OperationType::Delete, [new NoContent(), new MetaResult()]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function validateRejectsAnEmptySet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OperationResponses::validate(OperationType::Create, []);
    }

    #[Test]
    public function validateRejectsADuplicateStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OperationResponses::validate(OperationType::Create, [new Created(), new Created()]);
    }

    #[Test]
    public function validateRejectsACarrierFromAnotherOperation(): void
    {
        // `Ok` (200) is valid on update/fetch, and 200 is even a valid delete status —
        // but a delete's 200 is `MetaResult`, so `Ok` (not a DeleteResponse) is rejected.
        $this->expectException(\InvalidArgumentException::class);

        OperationResponses::validate(OperationType::Delete, [new Ok()]);
    }

    #[Test]
    public function validateRejectsAStatusOutsideTheOperationsSet(): void
    {
        // A hand-built create carrier with an out-of-set status (200 is not a create
        // success). Passes the marker check, fails the status check.
        $outOfSet = new class implements CreateResponse {
            public function status(): int
            {
                return 200;
            }

            public function jobType(): ?string
            {
                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);

        OperationResponses::validate(OperationType::Create, [$outOfSet]);
    }

    #[Test]
    public function validateRejectsMoreThanOneJobBearingResponse(): void
    {
        // Two distinct, op-valid update statuses both carrying a job type — only
        // reachable via hand-built carriers, so the guard is exercised with stubs.
        $first = new class implements UpdateResponse {
            public function status(): int
            {
                return 200;
            }

            public function jobType(): string
            {
                return 'jobs-a';
            }
        };
        $second = new class implements UpdateResponse {
            public function status(): int
            {
                return 202;
            }

            public function jobType(): string
            {
                return 'jobs-b';
            }
        };

        $this->expectException(\InvalidArgumentException::class);

        OperationResponses::validate(OperationType::Update, [$first, $second]);
    }
}
