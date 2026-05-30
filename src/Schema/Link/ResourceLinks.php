<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * The `links` member of a resource object: an optional `self` link plus any
 * arbitrary custom relations the spec permits. Construct-only.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#document-resource-object-links
 */
final readonly class ResourceLinks extends AbstractLinks
{
    /**
     * @param array<string, Link|null> $links arbitrary additional relations
     */
    public function __construct(string $baseUri = '', ?Link $self = null, array $links = [])
    {
        parent::__construct($baseUri, ['self' => $self, ...$links]);
    }

    /**
     * @param array<string, Link|null> $links
     */
    public static function withoutBaseUri(?Link $self = null, array $links = []): self
    {
        return new self('', $self, $links);
    }

    /**
     * @param array<string, Link|null> $links
     */
    public static function withBaseUri(string $baseUri, ?Link $self = null, array $links = []): self
    {
        return new self($baseUri, $self, $links);
    }

    public function self(): ?Link
    {
        return $this->link('self');
    }
}
