<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

/**
 * The `links` member of an error object: an optional `about` link plus zero or
 * more `type` links. Construct-only; `type` links are de-duplicated by href.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#error-objects
 */
final readonly class ErrorLinks extends AbstractLinks
{
    /** @var list<Link> */
    public array $types;

    /**
     * @param list<Link> $types
     */
    public function __construct(string $baseUri = '', ?Link $about = null, array $types = [])
    {
        $deduped = [];
        foreach ($types as $type) {
            $deduped[$type->href] = $type;
        }
        $this->types = array_values($deduped);

        parent::__construct($baseUri, ['about' => $about]);
    }

    /**
     * @param list<Link> $types
     */
    public static function withoutBaseUri(?Link $about = null, array $types = []): self
    {
        return new self('', $about, $types);
    }

    /**
     * @param list<Link> $types
     */
    public static function withBaseUri(string $baseUri, ?Link $about = null, array $types = []): self
    {
        return new self($baseUri, $about, $types);
    }

    public function about(): ?Link
    {
        return $this->link('about');
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        $links = parent::transform();

        if ($this->types !== []) {
            $links['type'] = array_map(
                fn(Link $link): string|array => $link->transform($this->baseUri),
                $this->types,
            );
        }

        return $links;
    }
}
