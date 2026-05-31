<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Profile;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\AbstractProfile;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class AbstractProfileTest extends TestCase
{
    #[Test]
    public function defaultsToNoKeywordsAndIdentityFinalisation(): void
    {
        $profile = new class extends AbstractProfile {
            public function uri(): string
            {
                return 'https://example.com/profiles/a';
            }
        };

        $body = ['data' => ['type' => 'user', 'id' => '1']];

        self::assertSame([], $profile->keywords());
        self::assertSame($body, $profile->finalizeDocument($body, StubJsonApiRequest::create()));
    }

    #[Test]
    public function subclassCanReserveKeywordsAndAugmentTheDocument(): void
    {
        $profile = new class extends AbstractProfile {
            public function uri(): string
            {
                return 'https://example.com/profiles/version';
            }

            public function keywords(): array
            {
                return ['version'];
            }

            public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
            {
                $meta = $document['meta'] ?? [];
                $document['meta'] = [...(\is_array($meta) ? $meta : []), 'version' => 3];

                return $document;
            }
        };

        self::assertSame(['version'], $profile->keywords());
        self::assertSame(
            ['data' => [], 'meta' => ['version' => 3]],
            $profile->finalizeDocument(['data' => []], StubJsonApiRequest::create()),
        );
    }
}
