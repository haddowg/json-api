<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * The top-level `links` member of a JSON:API document: an optional `self` and
 * `related` link, the pagination links (`first`/`prev`/`next`/`last`) and zero
 * or more profile links. Construct-only; profile links are de-duplicated by
 * href and emitted as a `profile` array.
 *
 * @see https://jsonapi.org/format/1.1/#document-top-level
 */
final readonly class DocumentLinks extends AbstractLinks
{
    /** @var list<Link> */
    public array $profiles;

    /**
     * @param list<Link>               $profiles
     * @param array<string, Link|null> $links    arbitrary additional relations
     */
    public function __construct(
        string $baseUri = '',
        ?Link $self = null,
        ?Link $related = null,
        // The pagination relations are emitted automatically from the `Pagination\Page`
        // value objects via `DataResponse::fromPage()`; these params remain as a
        // direct way to set them on a non-paginated document.
        ?Link $first = null,
        ?Link $prev = null,
        ?Link $next = null,
        ?Link $last = null,
        array $profiles = [],
        array $links = [],
    ) {
        $deduped = [];
        foreach ($profiles as $profile) {
            $deduped[$profile->href] = $profile;
        }
        $this->profiles = array_values($deduped);

        parent::__construct(
            $baseUri,
            [
                'self' => $self,
                'related' => $related,
                'first' => $first,
                'prev' => $prev,
                'next' => $next,
                'last' => $last,
                ...$links,
            ],
        );
    }

    /**
     * @param list<Link>               $profiles
     * @param array<string, Link|null> $links
     */
    public static function withoutBaseUri(
        ?Link $self = null,
        ?Link $related = null,
        ?Link $first = null,
        ?Link $prev = null,
        ?Link $next = null,
        ?Link $last = null,
        array $profiles = [],
        array $links = [],
    ): self {
        return new self('', $self, $related, $first, $prev, $next, $last, $profiles, $links);
    }

    /**
     * @param list<Link>               $profiles
     * @param array<string, Link|null> $links
     */
    public static function withBaseUri(
        string $baseUri,
        ?Link $self = null,
        ?Link $related = null,
        ?Link $first = null,
        ?Link $prev = null,
        ?Link $next = null,
        ?Link $last = null,
        array $profiles = [],
        array $links = [],
    ): self {
        return new self($baseUri, $self, $related, $first, $prev, $next, $last, $profiles, $links);
    }

    public function self(): ?Link
    {
        return $this->link('self');
    }

    public function related(): ?Link
    {
        return $this->link('related');
    }

    public function first(): ?Link
    {
        return $this->link('first');
    }

    public function prev(): ?Link
    {
        return $this->link('prev');
    }

    public function next(): ?Link
    {
        return $this->link('next');
    }

    public function last(): ?Link
    {
        return $this->link('last');
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $links = parent::transform();

        if ($this->profiles !== []) {
            $links['profile'] = array_map(
                fn(Link $link): string|array => $link->transform($this->baseUri),
                $this->profiles,
            );
        }

        return $links;
    }
}
