<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

use haddowg\JsonApi\OpenApi\Schema;

/**
 * A composite-schema constraint: composes a set of member {@see Schema} fragments as
 * `oneOf` / `anyOf` / `allOf` (with an optional `discriminator` for a `oneOf`) and
 * contributes them to the owning field's schema via the self-describing
 * {@see ProvidesJsonSchema} seam.
 *
 * It is the **pass-through** counterpart to the constructive composite field types:
 * where {@see \haddowg\JsonApi\Resource\Field\Obj} and
 * {@see \haddowg\JsonApi\Resource\Field\OneOf} own hydration (they read/write typed
 * children, so a variant can cast and map to columns), `Shape` only *documents and
 * validates* a value the field otherwise stores opaquely — attach it to a JSON-object
 * field (e.g. {@see \haddowg\JsonApi\Resource\Field\ArrayHash}) with `constrain()`,
 * and use the field's `serializeUsing()`/`fillUsing()` hooks for any bespoke
 * construction. The value validates against the composed schema (the opis body
 * validator natively; each framework adapter maps the combinator to its native
 * rules).
 *
 * Members are raw {@see Schema} fragments, so the full JSON Schema vocabulary is
 * available (mixed-type unions, nested composites, `$ref`s). The combinator is added
 * to the field's accumulated schema per the {@see ProvidesJsonSchema} contract, so
 * attach it to a field whose base type is compatible with the members (an object
 * field for object variants); the combinator is the authoritative shape.
 */
final readonly class Shape implements ProvidesJsonSchema
{
    /**
     * @param 'oneOf'|'anyOf'|'allOf' $combinator
     * @param list<Schema>            $members
     */
    private function __construct(
        private string $combinator,
        private array $members,
        private ?string $discriminator,
        private Context $context,
    ) {}

    /**
     * The value must match **exactly one** of the member schemas (`oneOf`). Pair with
     * {@see discriminator()} for a discriminated union.
     */
    public static function oneOf(Schema ...$members): self
    {
        return new self('oneOf', \array_values($members), null, Context::always());
    }

    /**
     * The value must match **at least one** of the member schemas (`anyOf`).
     */
    public static function anyOf(Schema ...$members): self
    {
        return new self('anyOf', \array_values($members), null, Context::always());
    }

    /**
     * The value must match **all** of the member schemas (`allOf` — intersection /
     * object mixin).
     */
    public static function allOf(Schema ...$members): self
    {
        return new self('allOf', \array_values($members), null, Context::always());
    }

    /**
     * Adds an OpenAPI `discriminator` naming the property whose value selects the
     * matching member (only meaningful for a {@see oneOf()}).
     */
    public function discriminator(string $property): self
    {
        return new self($this->combinator, $this->members, $property, $this->context);
    }

    /**
     * Applies only on create (POST) requests.
     */
    public function onCreate(): self
    {
        return new self($this->combinator, $this->members, $this->discriminator, Context::onlyCreate());
    }

    /**
     * Applies only on update (PATCH) requests.
     */
    public function onUpdate(): self
    {
        return new self($this->combinator, $this->members, $this->discriminator, Context::onlyUpdate());
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function contribute(Schema $schema): Schema
    {
        $schema = match ($this->combinator) {
            'oneOf' => $schema->withOneOf($this->members),
            'anyOf' => $schema->withAnyOf($this->members),
            'allOf' => $schema->withAllOf($this->members),
            default => $schema,
        };

        if ($this->discriminator !== null) {
            $schema = $schema->withDiscriminator($this->discriminator);
        }

        return $schema;
    }
}
