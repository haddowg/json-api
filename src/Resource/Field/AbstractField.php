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
 * Convenience base implementing the common {@see FieldInterface} fluent surface:
 * read-only state, hidden/sparse flags, the serialize/hydrate hook closures
 * (`serializeUsing`/`extractUsing`/`deserializeUsing`/`fillUsing`), the
 * constraint-list machinery and the `onCreate()` / `onUpdate()` context
 * builders.
 *
 * Fields are **mutable builders**: the fluent methods mutate and return
 * `$this`, so a field is declared in one expression
 * (`Str::make('title')->required()->maxLength(200)`). Type-specific constraint
 * helpers (`minLength()`, `min()`, `before()`, …) live on the concrete field
 * classes; the casting of raw values is done by overriding
 * {@see serializeValue()} / {@see deserializeValue()}.
 */
abstract class AbstractField implements \haddowg\JsonApi\Resource\Field\FieldInterface
{
    protected ?string $column;

    protected bool $readOnlyOnCreate = false;

    protected bool $readOnlyOnUpdate = false;

    protected bool $writeOnly = false;

    protected bool $hidden = false;

    /**
     * Request predicate gating read-only-on-create: when set, the field is
     * read-only for a create request iff the closure returns `true`. Independent
     * of {@see $readOnlyOnCreate} (the unconditional flag).
     *
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $readOnlyOnCreateWhen = null;

    /**
     * Request predicate gating read-only-on-update.
     *
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $readOnlyOnUpdateWhen = null;

    /**
     * Request predicate gating write-only: when set, the field is write-only for
     * a request iff the closure returns `true`.
     *
     * @var \Closure(JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $writeOnlyWhen = null;

    /**
     * Model + request predicate gating hidden: when set, the field is hidden for
     * a request iff the closure returns `true`.
     *
     * @var \Closure(mixed, JsonApiRequestInterface): bool|null
     */
    protected ?\Closure $hiddenWhen = null;

    protected bool $sparseField = true;

    protected bool $sortable = false;

    /**
     * When non-null, this attribute is **flattened from a chain of declared,
     * to-one relations' related model** rather than read/written on the owning
     * model directly: a `.`-separated path (`'author'` single-hop, or
     * `'publisher.country'` multi-hop) is walked relation by relation (each
     * honouring its own `column()`/`storedAs()`), and the field's own
     * `column() ?? name()` is read off / written onto the *final* related object
     * in the chain. Set by {@see on()}; read by {@see relatedVia()}. Mutually
     * exclusive with {@see $extractUsing} and a computed value closure (a
     * flattened attribute has a concrete backing member).
     */
    protected ?string $relatedVia = null;

    /**
     * @var list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    protected array $constraints = [];

    /**
     * Human-readable description surfaced by the OpenAPI generator. `null` = none.
     */
    protected ?string $description = null;

    /**
     * Whether an example value has been declared (distinct from a declared `null`
     * example, which {@see $example} alone cannot represent).
     */
    protected bool $hasExample = false;

    /**
     * The declared example value surfaced by the OpenAPI generator.
     */
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

    final public function __construct(
        protected string $name,
        ?string $column = null,
    ) {
        $this->column = $column ?? $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function column(): ?string
    {
        return $this->column;
    }

    public function relatedVia(): ?string
    {
        return $this->relatedVia;
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
     * `$callback` on read and the field is read-only on both create and update
     * (a derived value has nothing to write back). Sugar over the lower-level
     * primitives — {@see computed()} (no backing column) + {@see extractUsing()}
     * (the value hook) + {@see readOnly()} — and the documented attribute API for
     * a computed member. The callback receives the domain model, the request, and
     * the field name, and owns the serialized output (no {@see serializeValue()}
     * cast is applied). Mutually exclusive with {@see on()}.
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
     * chain, honouring the field's own `column()`/`storedAs()`. Every segment must
     * be a declared {@see RelationInterface} and **to-one** — enforced fail-loud at
     * boot / container warm-up by the host's eager-load validator (an unknown
     * segment, or a to-many segment, is a developer-facing `\LogicException`). A
     * segment MAY be {@see hidden()} (the idiomatic "internal association" backing a
     * flattened attribute that never renders as a relationship).
     *
     * On read, any intermediate null short-circuits the chain → a null attribute
     * value; on write, any null hop is a 422 (require-exists — no auto-instantiate).
     * The normal {@see serializeValue()} / {@see deserializeValue()} cast still
     * applies. Mutually exclusive with {@see computedUsing()} and
     * {@see extractUsing()} (a flattened attribute reads its own backing member off
     * the related object, so a value hook would have nothing to act on).
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
     * returns `true`) — e.g. `readOnly(fn($req) => !$req->getHeaderLine('X-Role'))`.
     * A request-aware read-only field is not *unconditionally* read-only, so the
     * superset schema still places it in the request body.
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
     * Marks the field write-only: it is **accepted on write** (hydrated on both
     * create and update, and still validated) but **never rendered** — it is
     * skipped in the attribute render exactly where sparse-fieldset filtering
     * happens, so it appears on no read (single, collection, included, related)
     * and a `fields[type]` parameter naming it cannot resurrect it. The inverse
     * of {@see readOnly()}. Intended for write-once secrets a client sets but the
     * server never echoes back (passwords, API tokens). Declaring a field both
     * write-only and read-only is contradictory (it could be neither read nor
     * written) and throws a {@see \LogicException}.
     *
     * A future OpenAPI generator reads {@see isWriteOnly()} to place the member in
     * the request schema only.
     *
     * Pass a closure to make the decision request-aware (write-only **for this
     * request** iff the closure returns `true`). The contradiction guard only
     * trips for the *unconditional × unconditional* case: declaring a field
     * `writeOnly(fn)` and `readOnly(fn)` (or one of each) is coherent — each
     * resolver is individually sound and the decision is deferred to request time.
     *
     * @param \Closure(JsonApiRequestInterface): bool|null $when
     * @return static
     */
    public function writeOnly(?\Closure $when = null): static
    {
        if ($when === null) {
            // Unconditional write-only contradicts an unconditional read-only only.
            // A read-only *predicate* defers to request time, so it does not trip
            // the guard (the resolvers stay individually coherent).
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
     * receiving the domain model and the request) — e.g.
     * `hidden(fn($model, $req) => !$req->getHeaderLine('X-Role'))`. A
     * request-aware hidden field is not *unconditionally* hidden, so it still
     * flows to the render loop (where {@see isHiddenFor()} resolves it) and the
     * superset schema still documents it.
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
     * **backed-enum cases**; cases are normalized to their backing scalar value
     * (so the stored {@see In} always carries plain scalars). When every member
     * is a case of one backed enum, that enum's class-string is retained on the
     * {@see In} so the OpenAPI generator can emit richer enum metadata.
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
     * Restricts the value to the backing scalars of a backed enum's cases. The
     * field's schema type follows the enum's backing type (string or integer),
     * and the enum class-string is retained on the {@see In} for the OpenAPI
     * generator (var-names, per-value descriptions, a reusable component).
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
    public function description(string $description): static
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
     * Scopes every constraint appended inside `$builder` to create (POST)
     * requests.
     *
     * @param \Closure(static): void $builder
     * @return static
     */
    public function onCreate(\Closure $builder): static
    {
        return $this->withContext(Context::onlyCreate(), $builder);
    }

    /**
     * Scopes every constraint appended inside `$builder` to update (PATCH)
     * requests.
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
     * rules the built-in helpers don't cover (pass any
     * {@see \haddowg\JsonApi\Resource\Constraint\ConstraintInterface}, your own
     * implementations included). Each constraint carries its own
     * {@see \haddowg\JsonApi\Resource\Constraint\Context}; unlike the built-in
     * helpers, `constrain()` does not re-stamp it, so scope a custom constraint by
     * constructing it with the context you want. Constraints added inside a
     * `when()` builder are captured into that `When` like any other.
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
     * The condition is opaque PHP, so it is not round-tripped to JSON Schema;
     * framework adapters that execute validation evaluate it.
     *
     * The condition receives the value first and the request second (nullable —
     * `null` for a context with no request, e.g. entity-level or filter-side
     * validation), so a `fn($value)` closure keeps binding unchanged while a
     * `fn($value, $request)` closure can also branch on the caller. `When` is
     * metadata here — the bundle's constraint translator is the only execution
     * site — so widening the signature is documentation only.
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
     * (Symfony's `AtLeastOneOf`). Use {@see sequentially()} for an alternative made
     * of several rules.
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

    public function isReadOnly(bool $creating): bool
    {
        return $creating ? $this->readOnlyOnCreate : $this->readOnlyOnUpdate;
    }

    public function isReadOnlyFor(bool $creating, JsonApiRequestInterface $request): bool
    {
        if ($this->isReadOnly($creating)) {
            return true;
        }

        $predicate = $creating ? $this->readOnlyOnCreateWhen : $this->readOnlyOnUpdateWhen;

        return $predicate !== null && $predicate($request);
    }

    public function isWriteOnly(): bool
    {
        return $this->writeOnly;
    }

    public function isWriteOnlyFor(JsonApiRequestInterface $request): bool
    {
        return $this->writeOnly || ($this->writeOnlyWhen !== null && ($this->writeOnlyWhen)($request));
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function isHiddenFor(JsonApiRequestInterface $request, mixed $model): bool
    {
        return $this->hidden || ($this->hiddenWhen !== null && ($this->hiddenWhen)($model, $request));
    }

    public function isSparseField(): bool
    {
        return $this->sparseField;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function hasExample(): bool
    {
        return $this->hasExample;
    }

    public function getExample(): mixed
    {
        return $this->example;
    }

    public function constraints(): array
    {
        return $this->constraints;
    }

    public function serialize(mixed $model, JsonApiRequestInterface $request, string $name): mixed
    {
        if ($this->serializeUsing !== null) {
            return ($this->serializeUsing)($model, $request, $name);
        }

        if ($this->extractUsing !== null) {
            return ($this->extractUsing)($model, $request, $name);
        }

        $raw = Accessor::get($model, $this->column ?? $name);

        return $this->serializeValue($raw);
    }

    /**
     * Serializes the field's value from the domain object alone, without a
     * request. Used for request-independent members such as the resource `id`:
     * an identity must not vary by request, so only the backing column and the
     * value cast are consulted — the request-aware {@see serializeUsing()} /
     * {@see extractUsing()} hooks are not.
     *
     * @internal
     */
    public function serializeWithoutRequest(mixed $model): mixed
    {
        $raw = Accessor::get($model, $this->column ?? $this->name);

        return $this->serializeValue($raw);
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->fillUsing !== null) {
            $result = ($this->fillUsing)($model, $value, $data, $this->name);

            return $result ?? $model;
        }

        $column = $this->column;
        if ($column === null) {
            return $model;
        }

        $value = $this->deserializeUsing !== null
            ? ($this->deserializeUsing)($value, $data)
            : $this->deserializeValue($value);

        return Accessor::set($model, $column, $value);
    }

    /**
     * Casts a raw domain value to its serialized representation. Override in
     * concrete field types (e.g. format a `DateTimeInterface`). Default: identity.
     */
    protected function serializeValue(mixed $raw): mixed
    {
        return $raw;
    }

    /**
     * Casts an incoming JSON value to its domain representation. Override in
     * concrete field types (e.g. parse a date string). Default: identity.
     */
    protected function deserializeValue(mixed $value): mixed
    {
        return $value;
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
     * Normalizes an enumerated-set list: each {@see \BackedEnum} case is reduced
     * to its backing scalar value, plain values pass through unchanged. When every
     * member is a case of one single backed enum, that enum's class-string is
     * returned alongside (so {@see In} can retain it); otherwise the class is
     * `null`. A pure {@see \UnitEnum} has no backing value and is left untouched.
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

            // A non-enum (or pure UnitEnum) member means the set is not a single
            // backed enum's case list.
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
