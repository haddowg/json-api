<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;

/**
 * A union attribute: the value is exactly **one of** a set of named object shapes
 * ({@see Obj} variants) — the built, readonly value object the engine walks. Authors
 * declare one with {@see make()}, which returns a mutable {@see OneOfBuilder}; the
 * resource **builds** it into this value object before use.
 *
 * A **discriminated** union names a discriminator property whose value names the active
 * variant; it is stored and rendered alongside the variant's children, and a partial
 * `PATCH` that leaves it out merges onto the stored variant. An **undiscriminated** union
 * has no such property: each variant must declare a required, always-rendered member no
 * other variant declares (its signature), the value's signature selects the variant, and
 * every write replaces the whole value, so an update requires what the variant requires.
 *
 * Like {@see Obj} the whole object lives in a single backing value. On hydrate the
 * selected variant's children run (so a variant's `DateTime` child still parses, and a
 * variant can map to columns via its children / `fillUsing`), giving per-variant
 * `/data/attributes/<field>/<child>` violation pointers. It projects to an OpenAPI
 * `oneOf` of closed branches, with a `discriminator` object and a `const` per branch
 * when discriminated.
 *
 * The construction path ({@see Obj}) covers the common case; a looser "one of these
 * shapes" whose members you only want documented and validated (not hydrated per
 * variant) is a schema-bearing constraint on a pass-through field instead.
 */
final readonly class OneOf extends AbstractFieldValue implements ProvidesFieldSchema, AcceptsDefaultOutputTimezoneInterface
{
    /**
     * Each undiscriminated variant's signature: the members it alone declares that every
     * write requires and every read renders.
     *
     * @var array<string, list<string>>
     */
    private array $signatures;

    /**
     * @param array<string, Obj> $variants
     *
     * @throws \LogicException when the union is undiscriminated and a variant has no signature, or a variant is open
     */
    public function __construct(
        FieldState $state,
        private ?string $discriminator = null,
        private array $variants = [],
    ) {
        parent::__construct($state);
        foreach ($variants as $name => $variant) {
            // An open branch would also match values meant for another branch, so the
            // `oneOf` would stop being exclusive.
            if ($variant->additionalProperties() !== null) {
                throw new \LogicException(\sprintf('OneOf "%s" variant "%s" cannot take additionalProperties(); a variant is closed. Open an Obj child of the variant instead.', $state->name, $name));
            }
        }
        $this->signatures = $discriminator === null ? self::signaturesOf($state->name, $variants) : [];
    }

    public static function make(string $name): OneOfBuilder
    {
        return new OneOfBuilder($name);
    }

    /**
     * The property whose value names the active variant, or `null` for an undiscriminated
     * union, whose variant is selected by its signature ({@see variantOf()}).
     */
    public function discriminatorName(): ?string
    {
        return $this->discriminator;
    }

    public function isDiscriminated(): bool
    {
        return $this->discriminator !== null;
    }

    /**
     * The members only this variant declares and every write requires, which select it in
     * an undiscriminated union. Empty for a discriminated union.
     *
     * @return list<string>
     */
    public function signatureOf(string $variant): array
    {
        return $this->signatures[$variant] ?? [];
    }

    /**
     * @return array<string, Obj>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * Passes the server's default output zone on to every variant, so a `DateTime`
     * in any of them renders in it too.
     */
    public function withDefaultOutputTimezone(string $timezone): static
    {
        $variants = \array_map(
            static fn(Obj $variant): Obj => $variant->withDefaultOutputTimezone($timezone),
            $this->variants,
        );

        return $variants === $this->variants ? $this : new self($this->state, $this->discriminator, $variants);
    }

    /**
     * The variant selected by a discriminator value, or `null` when the value names
     * no registered variant or the union is undiscriminated.
     */
    public function variantFor(mixed $discriminatorValue): ?Obj
    {
        return $this->discriminator !== null && \is_string($discriminatorValue) ? ($this->variants[$discriminatorValue] ?? null) : null;
    }

    /**
     * The name of the variant an object value selects, or `null` when it selects none:
     * its discriminator's value when discriminated, otherwise the one variant whose
     * signature it carries. A value carrying the signatures of two variants selects
     * neither.
     */
    public function variantNameOf(mixed $value): ?string
    {
        if (!\is_array($value) && !\is_object($value)) {
            return null;
        }

        if ($this->discriminator !== null) {
            $name = Accessor::get($value, $this->discriminator);

            return \is_string($name) && isset($this->variants[$name]) ? $name : null;
        }

        $selected = null;
        foreach ($this->signatures as $name => $signature) {
            foreach ($signature as $member) {
                if (\is_array($value) ? \array_key_exists($member, $value) : Accessor::get($value, $member) !== null) {
                    if ($selected !== null) {
                        return null;
                    }
                    $selected = $name;

                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * The variant an object value selects ({@see variantNameOf()}), or `null`.
     */
    public function variantOf(mixed $value): ?Obj
    {
        $name = $this->variantNameOf($value);

        return $name === null ? null : $this->variants[$name];
    }

    /**
     * Whether a write replaces the whole value rather than merging onto the stored one,
     * as every write of an undiscriminated union does.
     */
    public function writesWhole(): bool
    {
        return $this->discriminator === null;
    }

    /**
     * @param array<string, Obj> $variants
     *
     * @return array<string, list<string>>
     *
     * @throws \LogicException
     */
    private static function signaturesOf(string $field, array $variants): array
    {
        $declaredBy = [];
        foreach ($variants as $name => $variant) {
            foreach ($variant->children() as $child) {
                $declaredBy[$child->name()][] = $name;
            }
        }

        $signatures = [];
        foreach ($variants as $name => $variant) {
            $signature = [];
            foreach ($variant->children() as $child) {
                if (\count($declaredBy[$child->name()]) === 1 && self::marks($child)) {
                    $signature[] = $child->name();
                }
            }
            if ($signature === []) {
                throw new \LogicException(\sprintf(
                    'OneOf "%s" has no discriminator, so its variant "%s" must declare a member no other variant declares, required on every write and always rendered, to be told apart. Mark one required(), or name a discriminator().',
                    $field,
                    $name,
                ));
            }
            $signatures[$name] = $signature;
        }

        return $signatures;
    }

    /**
     * Whether a child can mark its variant: every write must send it and every read
     * render it, so a client can always tell the variant from the value.
     */
    private static function marks(FieldInterface $child): bool
    {
        if ($child->isHidden() || $child->isWriteOnly() || $child->hasConditionalReadVisibility()) {
            return false;
        }

        foreach ([true, false] as $creating) {
            $required = false;
            foreach ($child->constraints() as $constraint) {
                if ($constraint instanceof Required && $constraint->context()->appliesTo($creating)) {
                    $required = true;
                }
            }
            if (!$required) {
                return false;
            }
        }

        return true;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->state->serializeUsing !== null) {
            return ($this->state->serializeUsing)($model, $request, $name);
        }

        if ($this->state->extractUsing !== null) {
            return $this->serializeComputed(($this->state->extractUsing)($model, $request, $name), $request);
        }

        return $this->serializeStored(Accessor::get($model, $this->state->column ?? $name), $request);
    }

    /**
     * Renders a stored value through the variant its discriminator names. {@see ArrayList}
     * renders each element of a structured list through it.
     *
     * @internal
     */
    public function serializeStored(mixed $value, JsonApiRequestInterface $request): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        return $this->renderVariant($value, $request);
    }

    /**
     * Renders a value an `extractUsing()` / `computedUsing()` hook produced exactly as
     * {@see serializeStored()} renders a stored one, an object value included: the
     * selected variant's children render their own keys beside the discriminator, and
     * every other key is dropped. A value naming no variant is emitted as it is.
     *
     * @internal
     */
    public function serializeComputed(mixed $value, JsonApiRequestInterface $request): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (!\is_array($value) && !\is_object($value)) {
            return $value;
        }

        return $this->renderVariant($value, $request);
    }

    /**
     * @param array<array-key, mixed>|object $value
     */
    private function renderVariant(array|object $value, JsonApiRequestInterface $request): mixed
    {
        $name = $this->variantNameOf($value);
        if ($name === null) {
            // A value selecting no variant has none to render through, so emit it as-is
            // rather than silently dropping it.
            return \is_array($value) ? JsonObject::of($value) : $value;
        }

        $nested = $this->discriminator === null ? [] : [$this->discriminator => $name];
        foreach ($this->variants[$name]->children() as $child) {
            if ($child->isWriteOnlyFor($request) || $child->isHiddenFor($request, $value)) {
                continue;
            }

            $nested[$child->name()] = $child->serialize($value, $request, $child->name());
        }

        return $nested;
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->state->fillUsing !== null) {
            $result = ($this->state->fillUsing)($model, $value, $data, $this->state->name);

            return $result ?? $model;
        }

        $column = $this->state->column ?? $this->state->name;

        if ($value === null) {
            return Accessor::set($model, $column, null);
        }

        $value = $this->deserializeValue($value);
        if (!\is_array($value)) {
            return $model;
        }

        if ($this->discriminator === null) {
            // Undiscriminated: the value's signature selects the variant and every write
            // replaces the whole value, so nothing of the stored one carries over.
            $variant = $this->variantOf($value);
            if ($variant === null) {
                // No variant to hydrate against; store the raw value so the validator can
                // reject it (hydration does not validate).
                return Accessor::set($model, $column, $value);
            }

            $nested = JsonObject::hydrateChildren($this->name(), static fn(): mixed => Obj::hydrateChildrenOnto($variant->children(), [], $value, $data, $request, $creating));

            return Accessor::set($model, $column, $nested);
        }

        $existing = Accessor::get($model, $column);
        $existing = \is_array($existing) ? $existing : [];
        $existingDiscriminator = $existing[$this->discriminator] ?? null;

        // The incoming discriminator, falling back to the stored one on a partial PATCH
        // that does not restate it.
        $discriminatorValue = $value[$this->discriminator] ?? $existingDiscriminator;
        $variant = $this->variantFor($discriminatorValue);
        if ($variant === null) {
            // No variant to hydrate against; store the raw value so the validator can
            // reject the unknown/missing discriminator (hydration does not validate).
            return Accessor::set($model, $column, $value);
        }

        // Merge onto the stored object only when the variant is unchanged; a variant
        // switch starts fresh so stale keys of the previous shape do not linger.
        $nested = $existingDiscriminator === $discriminatorValue ? $existing : [];
        $nested[$this->discriminator] = $discriminatorValue;

        $nested = JsonObject::hydrateChildren($this->name(), static fn(): mixed => Obj::hydrateChildrenOnto($variant->children(), $nested, $value, $data, $request, $creating));

        return Accessor::set($model, $column, $nested);
    }

    /**
     * Builds one element of a structured {@see ArrayList} from its request value through
     * the variant its discriminator names, starting from nothing rather than a stored
     * element. A value naming no variant is kept as sent, as {@see hydrate()} keeps it.
     *
     * @internal
     *
     * @param array<array-key, mixed> $value
     * @param array<string, mixed>    $data
     */
    public function hydrateElement(array $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $name = $this->variantNameOf($value);
        if ($name === null) {
            return $value;
        }

        return Obj::hydrateChildrenOnto($this->variants[$name]->children(), $this->discriminator === null ? [] : [$this->discriminator => $name], $value, $data, $request, $creating);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        JsonObject::assertNotList($value, $this->name());

        return $value;
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        return $this->projectVariants($projector, $creating, $collector);
    }

    /**
     * The union's `oneOf`, with `$shapedByHook` standing in for a `serializeUsing()` hook
     * on the union itself when an enclosing {@see ArrayList}'s one renders it.
     *
     * @internal
     */
    public function projectVariants(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector, bool $shapedByHook = false): Schema
    {
        if ($this->discriminator === null && !$projector->isReading() && !$projector->writesWholeValue()) {
            // Every write builds an undiscriminated union whole, so an update branch
            // requires its signature, which keeps the branches exclusive.
            return $projector->wholeValue(fn(): Schema => $this->projectVariants($projector, $creating, $collector, $shapedByHook));
        }

        $shapedByHook = $shapedByHook || ($projector->isReading() && $this->rendersThroughSerializeHook());
        $branches = [];
        foreach ($this->variants as $variantName => $shape) {
            // The union is the parent, so its own serializeUsing() hook keeps the
            // variants' children out of the read `required`.
            $schema = $projector->projectNestedObject($this, $shape->children(), $creating, $collector, $shapedByHook);
            if ($this->discriminator === null) {
                // No member names the variant, so the branch's title does, giving a code
                // generator a name for the branch it reads.
                $branches[] = $schema->withTitle($variantName);

                continue;
            }

            // Each branch carries the discriminator as a const property (and, on create,
            // on a read the union renders itself and on any write of a list element, in
            // `required`) so a consumer can match the value to its variant.
            /** @var array<string, Schema> $properties */
            $properties = \is_array($schema->get('properties')) ? $schema->get('properties') : [];
            $properties[$this->discriminator] = Schema::ofType('string')->withConst($variantName);
            $schema = $schema->withProperties($properties);

            if ($creating || $projector->writesWholeValue() || ($projector->isReading() && !$shapedByHook)) {
                /** @var list<string> $required */
                $required = \is_array($schema->get('required')) ? \array_values($schema->get('required')) : [];
                if (!\in_array($this->discriminator, $required, true)) {
                    $required[] = $this->discriminator;
                }
                $schema = $schema->withRequired($required);
            }

            $branches[] = $schema;
        }

        $union = Schema::create()->withOneOf($branches);

        return $this->discriminator === null ? $union : $union->withDiscriminator($this->discriminator);
    }
}
