<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Profile;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class CountableProfileTest extends TestCase
{
    #[Test]
    public function advertisesItsCanonicalUri(): void
    {
        $profile = new CountableProfile();

        // The "Countable" rename uses the new /profiles/countable/ slug.
        self::assertSame('https://haddowg.github.io/json-api/profiles/countable/', $profile->uri());
        self::assertSame(CountableProfile::URI, $profile->uri());
    }

    #[Test]
    public function keepsTheWithCountQueryParameterFamily(): void
    {
        // The profile identity changed but the ?withCount param name did not.
        $profile = new CountableProfile();

        self::assertSame('withCount', CountableProfile::FAMILY);
        self::assertSame(['withCount'], $profile->keywords());
    }

    #[Test]
    public function reservesTheSelfTokenAndTotalMember(): void
    {
        self::assertSame('_self_', CountableProfile::SELF_TOKEN);
        self::assertSame('total', CountableProfile::META_MEMBER);
    }

    #[Test]
    public function familyBaseSatisfiesTheImplementationSpecificNamingRule(): void
    {
        // The spec requires a custom query-parameter family's base name to contain
        // at least one non a-z character; `withCount` carries an uppercase letter.
        self::assertSame(0, \preg_match('/^[a-z]+$/', CountableProfile::FAMILY));
    }

    #[Test]
    public function leavesTheDocumentUnchanged(): void
    {
        $profile = new CountableProfile();
        $body = ['data' => ['type' => 'album', 'id' => '1']];

        self::assertSame($body, $profile->finalizeDocument($body, StubJsonApiRequest::create()));
    }
}
