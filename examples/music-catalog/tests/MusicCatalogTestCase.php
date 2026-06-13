<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Tests;

use haddowg\JsonApi\Server\Server;
use PHPUnit\Framework\TestCase;

use function haddowg\JsonApi\Examples\MusicCatalog\bootstrap;

/**
 * The shared base for every music-catalog example test.
 *
 * It boots a freshly-seeded {@see Server} per test through the example app's
 * {@see bootstrap()} entry point — the single wiring source of truth — so writes
 * in one test never leak into another (each test gets its own in-memory store).
 * The one method tests reach for is {@see server()}; per-verb request helpers
 * (`get`/`post`/`patch`/`delete`) live in the individual suites since they vary in
 * the headers/bodies each one exercises.
 */
abstract class MusicCatalogTestCase extends TestCase
{
    private ?Server $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset between tests: the next server() call boots a fresh seeded store.
        $this->server = null;
    }

    /**
     * The fully-wired, freshly-seeded server under test, memoised for the duration
     * of a single test method.
     */
    protected function server(): Server
    {
        return $this->server ??= bootstrap();
    }
}
