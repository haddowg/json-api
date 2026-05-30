<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Trivial test proving the PHPUnit + autoloader toolchain is wired correctly.
 *
 * Removed in Phase 1 once real tests exist.
 */
final class SmokeTest extends TestCase
{
    #[Test]
    public function toolchainIsWired(): void
    {
        self::assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'The package requires PHP 8.3 or newer.',
        );
    }
}
