<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The fluent authoring surface for a {@see DeclaredLink}, returned by
 * {@see DeclaredLink::make()}. Each method mutates the builder and returns it, so a link
 * reads as one expression; {@see build()} checks the declaration and freezes it.
 *
 * The href comes from {@see hrefUsing()} (with the meta values from {@see metaUsing()}),
 * or from one {@see linkUsing()} closure that returns both.
 */
final class DeclaredLinkBuilder
{
    private ?string $description = null;

    private ?string $example = null;

    /** @var list<FieldInterface|FieldBuilderInterface> */
    private array $meta = [];

    private ?string $rel = null;

    private ?string $title = null;

    private ?string $type = null;

    private ?string $hreflang = null;

    private ?string $describedBy = null;

    /** @var (\Closure(mixed, JsonApiRequestInterface): ?string)|null */
    private ?\Closure $hrefUsing = null;

    /** @var (\Closure(mixed, JsonApiRequestInterface): array<string, mixed>)|null */
    private ?\Closure $metaUsing = null;

    /** @var (\Closure(mixed, JsonApiRequestInterface): (string|array<string, mixed>|null))|null */
    private ?\Closure $linkUsing = null;

    public function __construct(
        private readonly string $name,
    ) {}

    /**
     * Sets the description the OpenAPI projection gives the link.
     */
    public function describedAs(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Sets the example `href` the OpenAPI projection shows. Operation examples include the
     * link only when this and every meta member's example are declared.
     */
    public function example(string $href): self
    {
        $this->example = $href;

        return $this;
    }

    /**
     * Declares the link's `meta` members. Each is a field, so it carries its own schema,
     * description and example, and renders its value the way that field renders an
     * attribute. A relation or an `Id` cannot be a meta member.
     */
    public function meta(FieldInterface|FieldBuilderInterface ...$members): self
    {
        $this->meta = [...$this->meta, ...\array_values($members)];

        return $this;
    }

    /**
     * Sets the closure that builds the href for one resource. Returning `null` leaves the
     * link out of that resource's `links`, which is how a link is hidden per viewer or per
     * state.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): ?string $href
     */
    public function hrefUsing(\Closure $href): self
    {
        $this->hrefUsing = $href;

        return $this;
    }

    /**
     * Sets the closure that returns the meta values, keyed by member name. It runs only
     * when the href is not `null`.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): array<string, mixed> $meta
     */
    public function metaUsing(\Closure $meta): self
    {
        $this->metaUsing = $meta;

        return $this;
    }

    /**
     * Sets one closure that returns the href and the meta values together: `null` to leave
     * the link out, the href as a string, or `['href' => …, 'meta' => [...]]`. Use it in
     * place of {@see hrefUsing()} and {@see metaUsing()} when both come from one call, such
     * as signing an upload URL.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): (string|array<string, mixed>|null) $link
     */
    public function linkUsing(\Closure $link): self
    {
        $this->linkUsing = $link;

        return $this;
    }

    /**
     * Sets the link object's `rel` member.
     */
    public function rel(string $rel): self
    {
        $this->rel = $rel;

        return $this;
    }

    /**
     * Sets the link object's `title` member, a human-readable label.
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Sets the link object's `type` member, the media type of the link's target.
     */
    public function type(string $mediaType): self
    {
        $this->type = $mediaType;

        return $this;
    }

    /**
     * Sets the link object's `hreflang` member, the language of the link's target.
     */
    public function hreflang(string $hreflang): self
    {
        $this->hreflang = $hreflang;

        return $this;
    }

    /**
     * Sets the link object's `describedby` member, a URL describing the link's target.
     */
    public function describedBy(string $url): self
    {
        $this->describedBy = $url;

        return $this;
    }

    public function build(): DeclaredLink
    {
        if ($this->name === '') {
            throw new \LogicException('A declared link needs a name.');
        }
        if ($this->name === 'self') {
            throw new \LogicException('A declared link cannot be named "self": the server builds the resource\'s self link, and getLinks() can replace it.');
        }
        if ($this->linkUsing !== null && ($this->hrefUsing !== null || $this->metaUsing !== null)) {
            throw new \LogicException(\sprintf('The "%s" link sets linkUsing() together with hrefUsing() or metaUsing(); use one or the other.', $this->name));
        }
        if ($this->linkUsing === null && $this->hrefUsing === null) {
            throw new \LogicException(\sprintf('The "%s" link has no href: give it hrefUsing() or linkUsing().', $this->name));
        }

        $meta = [];
        foreach ($this->meta as $member) {
            $member = $member instanceof FieldBuilderInterface ? $member->build() : $member;
            if ($member instanceof RelationInterface || $member instanceof Id) {
                throw new \LogicException(\sprintf('The "%s" link\'s meta member "%s" is a relation or an id; meta members are attribute fields.', $this->name, $member->name()));
            }
            if (isset($meta[$member->name()])) {
                throw new \LogicException(\sprintf('The "%s" link declares the meta member "%s" twice.', $this->name, $member->name()));
            }
            $meta[$member->name()] = $member;
        }
        if ($meta !== [] && $this->linkUsing === null && $this->metaUsing === null) {
            throw new \LogicException(\sprintf('The "%s" link declares meta members but nothing supplies their values: give it metaUsing() or linkUsing().', $this->name));
        }

        return new DeclaredLink(
            name: $this->name,
            description: $this->description,
            example: $this->example,
            meta: \array_values($meta),
            rel: $this->rel,
            title: $this->title,
            type: $this->type,
            hreflang: $this->hreflang,
            describedBy: $this->describedBy,
            hrefUsing: $this->hrefUsing,
            metaUsing: $this->metaUsing,
            linkUsing: $this->linkUsing,
        );
    }
}
