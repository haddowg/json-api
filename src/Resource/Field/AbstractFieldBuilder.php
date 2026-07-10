<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\When;

/**
 * The mutable base for a **field builder**: the common fluent authoring surface an
 * author chains — read-only / hidden / sparse state, the serialize/hydrate hook
 * closures (`serializeUsing`/`extractUsing`/`deserializeUsing`/`fillUsing`), the
 * constraint-list machinery and the `onCreate()` / `onUpdate()` / `when()` context
 * builders. The fluent methods mutate and return `$this`, so a field is declared
 * in one expression (`Str::make('title')->required()->maxLength(200)`).
 *
 * {@see build()} snapshots the accumulated state into an immutable
 * {@see FieldState} (via {@see fieldState()}) and constructs the concrete readonly
 * {@see AbstractFieldValue} the engine walks. Type-specific authoring helpers
 * (`minLength()`, `min()`, `before()`, …) live on the concrete builders; the value
 * cast is done by overriding {@see AbstractFieldValue::serializeValue()} /
 * {@see AbstractFieldValue::deserializeValue()} on the mirror value object.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractFieldBuilder implements FieldBuilderInterface
{
    protected ?string $column;

    protected bool $readOnlyOnCreate = false;

    protected bool $readOnlyOnUpdate = false;

    protected bool $writeOnly = false;

    protected bool $hidden = false;

    /**
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $readOnlyOnCreateWhen = null;

    /**
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $readOnlyOnUpdateWhen = null;

    /**
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $writeOnlyWhen = null;

    /**
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $hiddenWhen = null;

    protected bool $sparseField = true;

    protected bool $sparseByDefault = false;

    protected bool $sortable = false;

    /**
     * When non-null, this attribute is flattened from a chain of declared, to-one
     * relations' related model. Set by {@see on()}, read by {@see FieldState::$relatedVia}.
     */
    protected ?string $relatedVia = null;

    /**
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    protected array $constraints = [];

    protected ?string $description = null;

    protected bool $hasExample = false;

    protected mixed $example = null;

    /**
     * @var \Closure(mixed, JsonApiRequestInterface, string): mixed|null
     */
    protected ?\Closure $serializeUsing = null;

    /**
     * @var \Closure(mixed, JsonApiRequestInterface, string): mixed|null
     */
    protected ?\Closure $extractUsing = null;

    /**
     * @var \Closure(mixed, array<string, mixed>): mixed|null
     */
    protected ?\Closure $deserializeUsing = null;

    /**
     * @var \Closure(mixed, mixed, array<string, mixed>, string): mixed|null
     */
    protected ?\Closure $fillUsing = null;

    /**
     * The context applied to constraints appended while inside an
     * `onCreate()` / `onUpdate()` builder; `null` means {@see Context::always()}.
     */
    private ?Context $contextOverride = null;

    /**
     * When non-null, {@see addConstraint()} appends here instead of to
     * {@see $constraints}: the capture buffer a {@see when()} builder collects its
     * wrapped constraints into before folding them into a single {@see When}.
     *
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>|null
     */
    private ?array $constraintBuffer = null;

    public function __construct(
        protected string $name,
        ?string $column = null,
    ) {
        $this->column = $column ?? $name;
    }

    /**
     * Freezes the accumulated authoring state into the concrete readonly value
     * object the engine consumes. Pure and idempotent.
     */
    abstract public function build(): FieldInterface;

    /**
     * The JSON:API member name this builder was declared with. Lets an author
     * introspect a builder to decorate it — e.g. a storage-specific subclass that
     * walks its inherited `fields()` and adds a constraint to the one it recognises.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Stores the value in a different domain-object member than the JSON:API
     * member name.
     *
     * @return static
     */
    public function storedAs(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Marks the field as computed (no backing column). Pair with
     * {@see extractUsing()} for the value.
     *
     * @return static
     */
    public function computed(): static
    {
        $this->column = null;

        return $this;
    }

    /**
     * Declares a **derived, read-only** attribute: the value is produced by
     * `$callback` on read and the field is read-only on both create and update.
     * Sugar over {@see computed()} + {@see extractUsing()} + {@see readOnly()}.
     * Mutually exclusive with {@see on()}.
     *
     * @param \Closure(mixed, JsonApiRequestInterface, string): mixed $callback
     * @return static
     */
    public function computedUsing(\Closure $callback): static
    {
        if ($this->relatedVia !== null) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot be both computedUsing() and on(): a computed value '
                . 'and a flattened related attribute are mutually exclusive.',
                $this->name,
            ));
        }

        $this->computed();
        $this->extractUsing = $callback;

        return $this->readOnly();
    }

    /**
     * Flattens this scalar attribute from a **chain of declared, to-one
     * relations**' related model: `$path` is a `.`-separated chain of relation
     * names — `'author'` (single hop) or `'publisher.country'` (multi-hop) — and
     * the value is read from / written onto the **final** related object in the
     * chain, honouring the field's own `column()`/`storedAs()`. Mutually exclusive
     * with {@see computedUsing()} and {@see extractUsing()}.
     *
     * @return static
     */
    public function on(string $path): static
    {
        if ($this->extractUsing !== null) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot combine on() with extractUsing()/computedUsing(): a '
                . 'flattened related attribute reads its own backing member off the '
                . 'related object.',
                $this->name,
            ));
        }

        $this->relatedVia = $path;

        return $this;
    }

    /**
     * Marks the field read-only on both create and update. Pass a closure to make
     * the decision request-aware (read-only **for this request** iff the closure
     * returns `true`). A request-aware read-only field is not *unconditionally*
     * read-only, so the superset schema still places it in the request body.
     *
     * @param \Closure(JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function readOnly(?\Closure $when = null): static
    {
        $this->guardNotWriteOnly('readOnly');
        if ($when === null) {
            $this->readOnlyOnCreate = true;
            $this->readOnlyOnUpdate = true;

            return $this;
        }

        $this->readOnlyOnCreateWhen = $when;
        $this->readOnlyOnUpdateWhen = $when;

        return $this;
    }

    /**
     * Marks the field read-only on create (POST) only. Pass a closure to gate it
     * on the request (see {@see readOnly()}).
     *
     * @param \Closure(JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function readOnlyOnCreate(?\Closure $when = null): static
    {
        $this->guardNotWriteOnly('readOnlyOnCreate');
        if ($when === null) {
            $this->readOnlyOnCreate = true;

            return $this;
        }

        $this->readOnlyOnCreateWhen = $when;

        return $this;
    }

    /**
     * Marks the field read-only on update (PATCH) only. Pass a closure to gate it
     * on the request (see {@see readOnly()}).
     *
     * @param \Closure(JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function readOnlyOnUpdate(?\Closure $when = null): static
    {
        $this->guardNotWriteOnly('readOnlyOnUpdate');
        if ($when === null) {
            $this->readOnlyOnUpdate = true;

            return $this;
        }

        $this->readOnlyOnUpdateWhen = $when;

        return $this;
    }

    /**
     * Marks the field write-only: accepted on write (hydrated and validated) but
     * never rendered. The inverse of {@see readOnly()}. Declaring a field both
     * unconditionally write-only and unconditionally read-only is contradictory and
     * throws a {@see \LogicException}; a read-only *predicate* defers to request
     * time and does not trip the guard.
     *
     * @param \Closure(JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function writeOnly(?\Closure $when = null): static
    {
        if ($when === null) {
            // Unconditional write-only contradicts an unconditional read-only only.
            if ($this->readOnlyOnCreate || $this->readOnlyOnUpdate) {
                throw new \LogicException(\sprintf(
                    'Field "%s" cannot be both write-only and read-only.',
                    $this->name,
                ));
            }

            $this->writeOnly = true;

            return $this;
        }

        $this->writeOnlyWhen = $when;

        return $this;
    }

    /**
     * Hides the field from serialization. Pass a closure to make the decision
     * request-aware (hidden **for this request** iff the closure returns `true`,
     * receiving the domain model and the request).
     *
     * @param \Closure(mixed, JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function hidden(?\Closure $when = null): static
    {
        if ($when === null) {
            $this->hidden = true;

            return $this;
        }

        $this->hiddenWhen = $when;

        return $this;
    }

    /**
     * @return static
     */
    public function notSparseField(): static
    {
        $this->sparseField = false;

        return $this;
    }

    /**
     * Marks the field **sparse by default**: omitted from the default response and
     * rendered ONLY when the client explicitly names it in a `fields[type]` member.
     * Intended for costly computed/derived attributes a client seldom needs.
     *
     * @return static
     */
    public function sparseByDefault(): static
    {
        $this->sparseByDefault = true;

        return $this;
    }

    /**
     * @return static
     */
    public function sortable(): static
    {
        $this->sortable = true;

        return $this;
    }

    /**
     * @param \Closure(mixed, JsonApiRequestInterface, string): mixed $callback
     * @return static
     */
    public function serializeUsing(\Closure $callback): static
    {
        $this->serializeUsing = $callback;

        return $this;
    }

    /**
     * @param \Closure(mixed, JsonApiRequestInterface, string): mixed $callback
     * @return static
     */
    public function extractUsing(\Closure $callback): static
    {
        if ($this->relatedVia !== null) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot combine extractUsing()/computedUsing() with on(): a '
                . 'flattened related attribute reads its own backing member off the '
                . 'related object.',
                $this->name,
            ));
        }

        $this->extractUsing = $callback;

        return $this;
    }

    /**
     * @param \Closure(mixed, array<string, mixed>): mixed $callback
     * @return static
     */
    public function deserializeUsing(\Closure $callback): static
    {
        $this->deserializeUsing = $callback;

        return $this;
    }

    /**
     * @param \Closure(mixed, mixed, array<string, mixed>, string): mixed $callback
     * @return static
     */
    public function fillUsing(\Closure $callback): static
    {
        $this->fillUsing = $callback;

        return $this;
    }

    /**
     * @return static
     */
    public function required(): static
    {
        return $this->addConstraint(new Required($this->currentContext()));
    }

    /**
     * Required on create (POST) only; absent on update (PATCH) means "no change".
     *
     * @return static
     */
    public function requiredOnCreate(): static
    {
        return $this->addConstraint(new Required(Context::onlyCreate()));
    }

    /**
     * Required when supplied on update (PATCH) only.
     *
     * @return static
     */
    public function requiredOnUpdate(): static
    {
        return $this->addConstraint(new Required(Context::onlyUpdate()));
    }

    /**
     * @return static
     */
    public function nullable(): static
    {
        return $this->addConstraint(new Nullable($this->currentContext()));
    }

    /**
     * Restricts the value to an enumerated set. Members may be plain scalars or
     * **backed-enum cases**; cases are normalized to their backing scalar value.
     * When every member is a case of one backed enum, that enum's class-string is
     * retained on the {@see In} for richer OpenAPI enum metadata.
     *
     * @param list<mixed> $values
     * @return static
     */
    public function in(array $values): static
    {
        [$scalars, $enumClass] = self::normalizeEnumValues($values);

        return $this->addConstraint(new In($scalars, $this->currentContext(), $enumClass));
    }

    /**
     * Restricts the value to the backing scalars of a backed enum's cases.
     *
     * @param class-string<\BackedEnum> $enum
     * @return static
     */
    public function enum(string $enum): static
    {
        $values = \array_map(static fn(\BackedEnum $case): int|string => $case->value, $enum::cases());

        return $this->addConstraint(new In(\array_values($values), $this->currentContext(), $enum));
    }

    /**
     * @param list<mixed> $values
     * @return static
     */
    public function notIn(array $values): static
    {
        [$scalars] = self::normalizeEnumValues($values);

        return $this->addConstraint(new NotIn($scalars, $this->currentContext()));
    }

    /**
     * Sets a human-readable description surfaced by the OpenAPI generator.
     *
     * @return static
     */
    public function describedAs(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Sets an example value surfaced by the OpenAPI generator. A declared `null`
     * is honoured (distinct from "no example").
     *
     * @return static
     */
    public function example(mixed $example): static
    {
        $this->hasExample = true;
        $this->example = $example;

        return $this;
    }

    /**
     * Scopes every constraint appended inside `$builder` to create (POST) requests.
     *
     * @param \Closure(static): void $builder
     * @return static
     */
    public function onCreate(\Closure $builder): static
    {
        return $this->withContext(Context::onlyCreate(), $builder);
    }

    /**
     * Scopes every constraint appended inside `$builder` to update (PATCH) requests.
     *
     * @param \Closure(static): void $builder
     * @return static
     */
    public function onUpdate(\Closure $builder): static
    {
        return $this->withContext(Context::onlyUpdate(), $builder);
    }

    /**
     * Attaches one or more constraints directly — the typed extension point for
     * rules the built-in helpers don't cover. Each constraint carries its own
     * {@see Context}; unlike the built-in helpers, `constrain()` does not re-stamp
     * it. Constraints added inside a `when()` builder are captured into that `When`.
     *
     * @return static
     */
    public function constrain(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface ...$constraints): static
    {
        foreach ($constraints as $constraint) {
            $this->addConstraint($constraint);
        }

        return $this;
    }

    /**
     * Applies the constraints appended inside `$builder` only when `$condition`
     * returns true for the value under validation. The wrapped constraints are
     * captured and folded into a single {@see When} carrying the current context.
     *
     * The condition receives the value first and the request second (nullable), so
     * a `fn($value)` closure keeps binding unchanged while a `fn($value, $request)`
     * closure can also branch on the caller.
     *
     * @param \Closure(mixed, JsonApiRequestInterface|null): bool $condition
     * @param \Closure(static): void $builder
     * @return static
     */
    public function when(\Closure $condition, \Closure $builder): static
    {
        $previous = $this->constraintBuffer;
        $this->constraintBuffer = [];

        try {
            $builder($this);
            $collected = $this->constraintBuffer ?? [];
        } finally {
            $this->constraintBuffer = $previous;
        }

        return $this->addConstraint(new When($condition, $collected, $this->currentContext()));
    }

    /**
     * Applies the given constraints to the value in order, stopping at the first
     * failure (Symfony's `Sequentially`); all must ultimately hold.
     *
     * @return static
     */
    public function sequentially(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface ...$constraints): static
    {
        return $this->addConstraint(new Sequentially(\array_values($constraints), $this->currentContext()));
    }

    /**
     * Passes if the value satisfies at least one of the given alternatives
     * (Symfony's `AtLeastOneOf`).
     *
     * @return static
     */
    public function atLeastOneOf(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface ...$alternatives): static
    {
        return $this->addConstraint(new AtLeastOneOf(\array_values($alternatives), $this->currentContext()));
    }

    /**
     * Compares this field's value to another field's value: the operator reads
     * `<this field> <operator> <$field>` (e.g. `endDate` `GreaterThan` `startDate`).
     *
     * @return static
     */
    public function compareWith(string $field, Comparison $operator): static
    {
        return $this->addConstraint(new CompareField($field, $operator, $this->currentContext()));
    }

    /**
     * Snapshots the accumulated shared authoring state into the immutable
     * {@see FieldState} a value object carries. Concrete {@see build()}
     * implementations pass this to their value-object constructor.
     */
    protected function fieldState(): FieldState
    {
        return new FieldState(
            name: $this->name,
            column: $this->column,
            relatedVia: $this->relatedVia,
            readOnlyOnCreate: $this->readOnlyOnCreate,
            readOnlyOnUpdate: $this->readOnlyOnUpdate,
            writeOnly: $this->writeOnly,
            hidden: $this->hidden,
            readOnlyOnCreateWhen: $this->readOnlyOnCreateWhen,
            readOnlyOnUpdateWhen: $this->readOnlyOnUpdateWhen,
            writeOnlyWhen: $this->writeOnlyWhen,
            hiddenWhen: $this->hiddenWhen,
            sparseField: $this->sparseField,
            sparseByDefault: $this->sparseByDefault,
            sortable: $this->sortable,
            constraints: $this->constraints,
            description: $this->description,
            hasExample: $this->hasExample,
            example: $this->example,
            serializeUsing: $this->serializeUsing,
            extractUsing: $this->extractUsing,
            deserializeUsing: $this->deserializeUsing,
            fillUsing: $this->fillUsing,
        );
    }

    /**
     * @return static
     */
    protected function addConstraint(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $constraint): static
    {
        if ($this->constraintBuffer !== null) {
            $this->constraintBuffer[] = $constraint;
        } else {
            $this->constraints[] = $constraint;
        }

        return $this;
    }

    /**
     * The context to attach to a constraint appended now: the active
     * `onCreate()`/`onUpdate()` override, or {@see Context::always()}.
     */
    protected function currentContext(): Context
    {
        return $this->contextOverride ?? Context::always();
    }

    /**
     * Normalizes an enumerated-set list: each {@see \BackedEnum} case is reduced to
     * its backing scalar value, plain values pass through unchanged. When every
     * member is a case of one single backed enum, that enum's class-string is
     * returned alongside; otherwise `null`.
     *
     * @param list<mixed> $values
     * @return array{0: list<mixed>, 1: class-string<\BackedEnum>|null}
     */
    private static function normalizeEnumValues(array $values): array
    {
        $scalars = [];
        $enumClass = null;
        $singleEnum = true;
        $sawEnum = false;

        foreach ($values as $value) {
            if ($value instanceof \BackedEnum) {
                $scalars[] = $value->value;
                if (!$sawEnum) {
                    $enumClass = $value::class;
                    $sawEnum = true;
                } elseif ($enumClass !== $value::class) {
                    $singleEnum = false;
                }

                continue;
            }

            $scalars[] = $value;
            $singleEnum = false;
        }

        return [$scalars, $singleEnum && $sawEnum ? $enumClass : null];
    }

    /**
     * Guards against declaring a field both read-only and write-only, which is
     * contradictory (it could be neither read nor written).
     */
    private function guardNotWriteOnly(string $method): void
    {
        if ($this->writeOnly) {
            throw new \LogicException(\sprintf(
                'Field "%s" cannot be both read-only and write-only; %s() was called on a write-only field.',
                $this->name,
                $method,
            ));
        }
    }

    /**
     * @param \Closure(static): void $builder
     * @return static
     */
    private function withContext(Context $context, \Closure $builder): static
    {
        $previous = $this->contextOverride;
        $this->contextOverride = $context;

        try {
            $builder($this);
        } finally {
            $this->contextOverride = $previous;
        }

        return $this;
    }
}
