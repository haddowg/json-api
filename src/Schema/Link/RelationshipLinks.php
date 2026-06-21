<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * The `links` member of a relationship object: optional `self` and `related`
 * links plus any arbitrary custom relations the spec permits. Construct-only.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
final readonly class RelationshipLinks extends AbstractLinks
{
    /**
     * @param array<string, Link|null> $links arbitrary additional relations
     */
    public function __construct(string $baseUri = '', ?Link $self = null, ?Link $related = null, array $links = [])
    {
        parent::__construct($baseUri, ['self' => $self, 'related' => $related, ...$links]);
    }

    /**
     * @param array<string, Link|null> $links
     */
    public static function withoutBaseUri(?Link $self = null, ?Link $related = null, array $links = []): self
    {
        return new self('', $self, $related, $links);
    }

    /**
     * @param array<string, Link|null> $links
     */
    public static function withBaseUri(string $baseUri, ?Link $self = null, ?Link $related = null, array $links = []): self
    {
        return new self($baseUri, $self, $related, $links);
    }

    protected function reboundTo(string $baseUri): static
    {
        return new self($baseUri, links: $this->links);
    }

    public function self(): ?Link
    {
        return $this->link('self');
    }

    public function related(): ?Link
    {
        return $this->link('related');
    }
}
