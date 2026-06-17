<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Profile;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class RelationshipQueriesProfileTest extends TestCase
{
    #[Test]
    public function advertisesItsCanonicalUri(): void
    {
        $profile = new RelationshipQueriesProfile();

        self::assertSame('https://haddowg.github.io/json-api/profiles/relationship-queries/', $profile->uri());
        self::assertSame(RelationshipQueriesProfile::URI, $profile->uri());
    }

    #[Test]
    public function reservesBothQueryParameterFamilies(): void
    {
        $profile = new RelationshipQueriesProfile();

        self::assertSame(['relatedQuery', 'rQ'], $profile->keywords());
    }

    #[Test]
    public function bothFamilyBasesSatisfyTheImplementationSpecificNamingRule(): void
    {
        // The spec requires a custom query-parameter family's base name to contain
        // at least one non a-z character; each base carries an uppercase letter.
        foreach ([RelationshipQueriesProfile::FAMILY, RelationshipQueriesProfile::FAMILY_SHORTHAND] as $family) {
            self::assertSame(0, \preg_match('/^[a-z]+$/', $family), "family '$family' must contain a non a-z character");
        }
    }

    #[Test]
    public function leavesTheDocumentUnchanged(): void
    {
        $profile = new RelationshipQueriesProfile();
        $body = ['data' => ['type' => 'album', 'id' => '1']];

        self::assertSame($body, $profile->finalizeDocument($body, StubJsonApiRequest::create()));
    }
}
