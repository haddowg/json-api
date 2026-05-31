<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Profile;

use haddowg\JsonApi\Schema\Profile\AbstractProfile;
use haddowg\JsonApi\Schema\Profile\ProfileAlreadyRegistered;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class ProfileRegistryTest extends TestCase
{
    #[Test]
    public function registersAndLooksUpProfilesByUri(): void
    {
        $profile = $this->profile('https://example.com/profiles/a');
        $registry = new ProfileRegistry($profile);

        self::assertTrue($registry->has('https://example.com/profiles/a'));
        self::assertSame($profile, $registry->get('https://example.com/profiles/a'));
    }

    #[Test]
    public function reportsAbsentProfiles(): void
    {
        $registry = new ProfileRegistry();

        self::assertFalse($registry->has('https://example.com/profiles/missing'));
        self::assertNull($registry->get('https://example.com/profiles/missing'));
    }

    #[Test]
    public function listsAllRegisteredProfilesInRegistrationOrder(): void
    {
        $a = $this->profile('https://example.com/profiles/a');
        $b = $this->profile('https://example.com/profiles/b');

        $registry = new ProfileRegistry($a);
        $registry->register($b);

        self::assertSame([$a, $b], $registry->all());
    }

    #[Test]
    public function rejectsDuplicateUriRegistration(): void
    {
        $registry = new ProfileRegistry($this->profile('https://example.com/profiles/a'));

        $this->expectException(ProfileAlreadyRegistered::class);
        $this->expectExceptionMessage("A profile is already registered for the URI 'https://example.com/profiles/a'.");

        $registry->register($this->profile('https://example.com/profiles/a'));
    }

    private function profile(string $uri): ProfileInterface
    {
        return new class ($uri) extends AbstractProfile {
            public function __construct(private readonly string $uri) {}

            public function uri(): string
            {
                return $this->uri;
            }
        };
    }
}
