<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionResponses::class)]
final class ActionResponsesTest extends TestCase
{
    #[Test]
    public function itAcceptsAUniqueValidActionResponseSet(): void
    {
        $this->expectNotToPerformAssertions();

        ActionResponses::validate([new ActionResource('exports'), new Accepted('jobs')]);
    }

    #[Test]
    public function itRejectsAnEmptySet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([]);
    }

    #[Test]
    public function itRejectsADuplicateStatusCode(): void
    {
        // ActionResource and MetaResult are both 200 — at most one 200 is meaningful.
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new ActionResource('exports'), new MetaResult()]);
    }

    #[Test]
    public function itRejectsMoreThanOneAsynchronousResponse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new Accepted('a'), new Accepted('b')]);
    }

    #[Test]
    public function itRejectsAResponseThatIsNotAnActionResponse(): void
    {
        // Ok is a valid CRUD/read response but is not a valid action response.
        $this->expectException(\InvalidArgumentException::class);

        ActionResponses::validate([new Ok()]);
    }
}
