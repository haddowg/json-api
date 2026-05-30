<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\ProfileLinkObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProfileLinkObjectTest extends TestCase
{
    #[Test]
    #[Group('spec:document-structure')]
    public function aliasReturnsConfiguredAlias(): void
    {
        $linkObject = new ProfileLinkObject('https://example.com/api/users', ['a' => 'b']);

        self::assertSame('b', $linkObject->alias('a'));
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function aliasReturnsEmptyStringWhenAbsent(): void
    {
        $linkObject = new ProfileLinkObject('https://example.com/api/users');

        self::assertSame('', $linkObject->alias('missing'));
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function exposesAliases(): void
    {
        $linkObject = new ProfileLinkObject('https://example.com/api/users', ['a' => 'b']);

        self::assertSame(['a' => 'b'], $linkObject->aliases);
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformIncludesAliases(): void
    {
        $linkObject = new ProfileLinkObject('https://example.com/api/users', ['a' => 'b']);

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'aliases' => ['a' => 'b'],
            ],
            $linkObject->transform(''),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformIncludesEmptyAliasesMap(): void
    {
        $linkObject = new ProfileLinkObject('https://example.com/api/users');

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'aliases' => [],
            ],
            $linkObject->transform(''),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformWithMetaAndSpecMembers(): void
    {
        $linkObject = new ProfileLinkObject(
            href: 'https://example.com/api/users',
            aliases: ['a' => 'b'],
            rel: 'profile',
            meta: ['abc' => 'def'],
        );

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'rel' => 'profile',
                'meta' => ['abc' => 'def'],
                'aliases' => ['a' => 'b'],
            ],
            $linkObject->transform(''),
        );
    }
}
