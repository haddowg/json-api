<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AcceptsDefaultOutputTimezoneInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\LinkObject;

/**
 * A named member of a resource object's `links` that a resource declares in
 * {@see \haddowg\JsonApi\Resource\AbstractResource::links()}, so the server renders it and
 * the OpenAPI projection describes it by name.
 *
 * Build one with {@see make()}, the way a field is built: the builder is the authoring
 * surface and this value object the consumption surface the renderer, the projector and
 * an adapter's metadata read.
 *
 * A link with no `meta` members and none of the static link-object members renders as a
 * bare URL string. Declaring any of them makes it a link object on every response, even
 * one where every meta member is hidden, so the shape never depends on the data.
 */
final readonly class DeclaredLink
{
    /**
     * Build through {@see make()}, which checks the declaration is complete.
     *
     * @param list<FieldInterface> $meta
     * @param (\Closure(mixed, JsonApiRequestInterface): ?string)|null $hrefUsing
     * @param (\Closure(mixed, JsonApiRequestInterface): array<string, mixed>)|null $metaUsing
     * @param (\Closure(mixed, JsonApiRequestInterface): (string|array<string, mixed>|null))|null $linkUsing
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $example = null,
        public array $meta = [],
        public ?string $rel = null,
        public ?string $title = null,
        public ?string $type = null,
        public ?string $hreflang = null,
        public ?string $describedBy = null,
        private ?\Closure $hrefUsing = null,
        private ?\Closure $metaUsing = null,
        private ?\Closure $linkUsing = null,
    ) {}

    public static function make(string $name): DeclaredLinkBuilder
    {
        return new DeclaredLinkBuilder($name);
    }

    /**
     * Whether the link renders as a link object rather than a bare URL string: it declares
     * `meta` members or a static link-object member.
     */
    public function rendersAsObject(): bool
    {
        return $this->meta !== []
            || $this->rel !== null
            || $this->title !== null
            || $this->type !== null
            || $this->hreflang !== null
            || $this->describedBy !== null;
    }

    /**
     * The static link-object members in the order a link object renders them.
     *
     * @return array<string, string>
     */
    public function staticMembers(): array
    {
        return \array_filter([
            'rel' => $this->rel,
            'title' => $this->title,
            'type' => $this->type,
            'hreflang' => $this->hreflang,
        ], static fn(?string $value): bool => $value !== null);
    }

    /**
     * The link for one resource, or `null` when its href resolves to `null` (or an empty
     * string), which leaves it out of the resource's `links`.
     *
     * Each meta member reads its value from the meta array the closure returned as a field
     * reads from a model, so it renders the way an attribute of that type would: a
     * `DateTime` is formatted, a member the array lacks renders `null`, and a key no member
     * declares is dropped. A member hidden for the request and model is left out.
     * `$outputTimezone` is the server's default output zone for temporal members.
     */
    public function resolve(mixed $model, JsonApiRequestInterface $request, ?string $outputTimezone = null): ?Link
    {
        [$href, $values] = $this->resolveHrefAndMeta($model, $request);
        if ($href === null || $href === '') {
            return null;
        }

        if (!$this->rendersAsObject()) {
            return new Link($href);
        }

        $meta = [];
        foreach ($this->meta as $member) {
            if ($member->isHidden() || $member->isWriteOnly() || $member->isHiddenFor($request, $model)) {
                continue;
            }
            if ($outputTimezone !== null && $member instanceof AcceptsDefaultOutputTimezoneInterface) {
                $member = $member->withDefaultOutputTimezone($outputTimezone);
            }
            $meta[$member->name()] = $member->serialize($values, $request, $member->name());
        }

        return new LinkObject(
            $href,
            rel: $this->rel ?? '',
            title: $this->title ?? '',
            type: $this->type ?? '',
            hreflang: $this->hreflang ?? '',
            meta: $meta,
            describedby: $this->describedBy === null ? null : new Link($this->describedBy),
        );
    }

    /**
     * @return array{?string, array<string, mixed>}
     */
    private function resolveHrefAndMeta(mixed $model, JsonApiRequestInterface $request): array
    {
        if ($this->linkUsing !== null) {
            $resolved = ($this->linkUsing)($model, $request);
            if ($resolved === null || \is_string($resolved)) {
                return [$resolved, []];
            }

            $href = $resolved['href'] ?? null;
            if ($href !== null && !\is_string($href)) {
                throw new \UnexpectedValueException(\sprintf('The "%s" link\'s linkUsing() closure returned an href that is not a string.', $this->name));
            }
            $meta = $resolved['meta'] ?? [];
            if (!\is_array($meta)) {
                throw new \UnexpectedValueException(\sprintf('The "%s" link\'s linkUsing() closure returned a meta that is not an array.', $this->name));
            }

            /** @var array<string, mixed> $meta */
            return [$href, $meta];
        }

        $href = $this->hrefUsing === null ? null : ($this->hrefUsing)($model, $request);
        if ($href === null || $href === '') {
            return [null, []];
        }

        return [$href, $this->metaUsing === null ? [] : ($this->metaUsing)($model, $request)];
    }
}
