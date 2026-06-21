<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Atomic;

use haddowg\JsonApi\Atomic\AtomicLoop;
use haddowg\JsonApi\Atomic\AtomicLoopBackendInterface;
use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Atomic\LocalIdRegistry;
use haddowg\JsonApi\Atomic\OperationDescriptor;
use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class AtomicLoopTest extends TestCase
{
    #[Test]
    public function commitsAndReturnsResultsInOrderOnSuccess(): void
    {
        $backend = new FakeAtomicBackend([
            AtomicResult::fromDocument(['data' => ['type' => 'articles', 'id' => '1']]),
            AtomicResult::empty(),
        ]);

        $response = (new AtomicLoop())->run($this->descriptors(2), $backend);

        self::assertInstanceOf(AtomicResultsResponse::class, $response);
        self::assertSame(['begin', 'executeOne', 'executeOne', 'commit'], $backend->calls);
        self::assertFalse($backend->rolledBack);

        $body = $this->decode($response);
        self::assertSame(
            [
                ['data' => ['type' => 'articles', 'id' => '1']],
                [],
            ],
            $body['atomic:results'],
        );
    }

    #[Test]
    public function threadsTheSameRegistryInstanceToEveryExecuteOne(): void
    {
        $backend = new FakeAtomicBackend([AtomicResult::empty(), AtomicResult::empty(), AtomicResult::empty()]);

        (new AtomicLoop())->run($this->descriptors(3), $backend);

        self::assertCount(3, $backend->registries);
        self::assertSame($backend->registries[0], $backend->registries[1]);
        self::assertSame($backend->registries[1], $backend->registries[2]);
    }

    #[Test]
    public function rollsBackAndReturnsAPrefixedErrorWhenAnOperationThrows(): void
    {
        // Fails on op index 1 with an inner pointer /data/attributes/title.
        $backend = new FakeAtomicBackend(
            [AtomicResult::empty()],
            throwAtIndex: 1,
            exception: new FakeNestedPointerException(),
        );

        $response = (new AtomicLoop())->run($this->descriptors(3), $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);
        self::assertTrue($backend->rolledBack);
        self::assertNotContains('commit', $backend->calls);
        self::assertSame(['begin', 'executeOne', 'executeOne', 'rollback'], $backend->calls);

        $body = $this->decode($response);
        self::assertSame(
            [[
                'status' => '422',
                'code' => 'TITLE_INVALID',
                'title' => 'Invalid title',
                'source' => ['pointer' => '/atomic:operations/1/data/attributes/title'],
            ]],
            $body['errors'],
        );
    }

    #[Test]
    public function locatesAPointerlessErrorAtTheOperationItself(): void
    {
        $backend = new FakeAtomicBackend(
            [],
            throwAtIndex: 0,
            exception: new FakePointerlessException(),
        );

        $response = (new AtomicLoop())->run($this->descriptors(2), $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);
        self::assertTrue($backend->rolledBack);

        $body = $this->decode($response);
        self::assertSame(
            [[
                'status' => '409',
                'code' => 'CONFLICT',
                'title' => 'Conflict',
                'source' => ['pointer' => '/atomic:operations/0'],
            ]],
            $body['errors'],
        );
    }

    #[Test]
    public function rollsBackAndRethrowsANonJsonApiThrowable(): void
    {
        $backend = new FakeAtomicBackend([], throwAtIndex: 0, throwable: new \RuntimeException('boom'));

        try {
            (new AtomicLoop())->run($this->descriptors(1), $backend);
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
            self::assertTrue($backend->rolledBack);
            self::assertNotContains('commit', $backend->calls);

            return;
        }

        self::fail('Expected the RuntimeException to propagate.');
    }

    #[Test]
    public function theRolledBackErrorResponseAdvertisesTheAtomicExtension(): void
    {
        $backend = new FakeAtomicBackend(
            [],
            throwAtIndex: 0,
            exception: new FakeNestedPointerException(),
        );

        $response = (new AtomicLoop())->run($this->descriptors(1), $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertStringContainsString(
            'ext="https://jsonapi.org/ext/atomic"',
            $psr->getHeaderLine('Content-Type'),
        );
    }

    #[Test]
    public function preservesAParameterOnlySourceWithoutAddingAPointer(): void
    {
        $backend = new FakeAtomicBackend(
            [],
            throwAtIndex: 0,
            exception: new FakeParameterSourceException(),
        );

        $response = (new AtomicLoop())->run($this->descriptors(1), $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);

        $body = $this->decode($response);
        self::assertSame(
            [[
                'status' => '400',
                'code' => 'BAD_PARAM',
                'title' => 'Bad parameter',
                'source' => ['parameter' => 'include'],
            ]],
            $body['errors'],
        );
    }

    #[Test]
    public function rollsBackAndReturnsAnErrorWhenTheCommitThrows(): void
    {
        $backend = new FakeAtomicBackend(
            [AtomicResult::empty()],
            commitException: new FakePointerlessException(),
        );

        $response = (new AtomicLoop())->run($this->descriptors(1), $backend);

        self::assertInstanceOf(ErrorResponse::class, $response);
        self::assertTrue($backend->rolledBack);
        self::assertSame(['begin', 'executeOne', 'commit', 'rollback'], $backend->calls);

        $body = $this->decode($response);
        self::assertSame(
            [[
                'status' => '409',
                'code' => 'CONFLICT',
                'title' => 'Conflict',
                'source' => ['pointer' => '/atomic:operations/0'],
            ]],
            $body['errors'],
        );
    }

    #[Test]
    public function rollsBackAndRethrowsANonJsonApiThrowableFromTheCommit(): void
    {
        $backend = new FakeAtomicBackend([AtomicResult::empty()], commitThrowable: new \RuntimeException('commit boom'));

        try {
            (new AtomicLoop())->run($this->descriptors(1), $backend);
        } catch (\RuntimeException $exception) {
            self::assertSame('commit boom', $exception->getMessage());
            self::assertTrue($backend->rolledBack);
            self::assertSame(['begin', 'executeOne', 'commit', 'rollback'], $backend->calls);

            return;
        }

        self::fail('Expected the RuntimeException to propagate.');
    }

    /**
     * @return list<OperationDescriptor>
     */
    private function descriptors(int $count): array
    {
        $descriptors = [];
        for ($i = 0; $i < $count; $i++) {
            $descriptors[] = new OperationDescriptor(
                AtomicOperationCode::Add,
                null,
                '/articles',
                ['type' => 'articles'],
                $i,
            );
        }

        return $descriptors;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(AtomicResultsResponse|ErrorResponse $response): array
    {
        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($psr->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

/**
 * An in-test backend recording the loop's calls, returning canned results until an
 * optional failing index.
 */
final class FakeAtomicBackend implements AtomicLoopBackendInterface
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<LocalIdRegistry>
     */
    public array $registries = [];

    public bool $rolledBack = false;

    private int $cursor = 0;

    /**
     * @param list<AtomicResult> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly ?int $throwAtIndex = null,
        private readonly ?AbstractJsonApiException $exception = null,
        private readonly ?\Throwable $throwable = null,
        private readonly ?AbstractJsonApiException $commitException = null,
        private readonly ?\Throwable $commitThrowable = null,
    ) {}

    public function begin(): void
    {
        $this->calls[] = 'begin';
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';

        if ($this->commitThrowable !== null) {
            throw $this->commitThrowable;
        }

        if ($this->commitException !== null) {
            throw $this->commitException;
        }
    }

    public function rollback(): void
    {
        $this->calls[] = 'rollback';
        $this->rolledBack = true;
    }

    public function executeOne(OperationDescriptor $op, LocalIdRegistry $lids): AtomicResult
    {
        $this->calls[] = 'executeOne';
        $this->registries[] = $lids;

        if ($this->throwAtIndex === $op->index) {
            if ($this->throwable !== null) {
                throw $this->throwable;
            }

            if ($this->exception !== null) {
                throw $this->exception;
            }
        }

        $result = $this->results[$this->cursor] ?? AtomicResult::empty();
        $this->cursor++;

        return $result;
    }
}

/**
 * A JSON:API exception whose error already carries a `source.pointer`.
 */
final class FakeNestedPointerException extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Title invalid', 422);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '422',
                code: 'TITLE_INVALID',
                title: 'Invalid title',
                source: ErrorSource::fromPointer('/data/attributes/title'),
            ),
        ];
    }
}

/**
 * A JSON:API exception whose error carries no `source` at all.
 */
final class FakePointerlessException extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Nope', 409);
    }

    public function getErrors(): array
    {
        return [
            new Error(status: '409', code: 'CONFLICT', title: 'Conflict'),
        ];
    }
}

/**
 * A JSON:API exception whose error source carries a `parameter` rather than a
 * `pointer` — the prefixer must leave it untouched (never add a sibling pointer).
 */
final class FakeParameterSourceException extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Bad parameter', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'BAD_PARAM',
                title: 'Bad parameter',
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
