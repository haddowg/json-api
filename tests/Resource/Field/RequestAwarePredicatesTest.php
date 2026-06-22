<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AbstractField;
use haddowg\JsonApi\Resource\Field\AbstractRelation;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The request-aware visibility / writability / relationship-authz predicate
 * resolvers. The closures branch on an `X-Role` header so a single fixture can
 * exercise both the restricted and permissive caller.
 */
#[CoversClass(AbstractField::class)]
#[CoversClass(AbstractRelation::class)]
#[CoversClass(Str::class)]
#[CoversClass(BelongsTo::class)]
#[CoversClass(HasMany::class)]
final class RequestAwarePredicatesTest extends TestCase
{
    // ---- hidden(closure) ----------------------------------------------------

    #[Test]
    public function hiddenClosureGatesOnTheRequestAndModel(): void
    {
        $field = Str::make('secret')->hidden(
            static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        // A closure-declared field is NOT unconditionally hidden, so the static
        // getter stays permissive (the superset schema still documents it).
        self::assertFalse($field->isHidden());

        self::assertTrue($field->isHiddenFor($this->guest(), ['id' => '1']));
        self::assertFalse($field->isHiddenFor($this->admin(), ['id' => '1']));
    }

    #[Test]
    public function unconditionalHiddenPrecedesAnyRequest(): void
    {
        $field = Str::make('secret')->hidden();

        self::assertTrue($field->isHidden());
        // Unconditional restriction wins regardless of the caller.
        self::assertTrue($field->isHiddenFor($this->admin(), null));
        self::assertTrue($field->isHiddenFor($this->guest(), null));
    }

    #[Test]
    public function isHiddenForDefaultsFalseWithNoDeclaration(): void
    {
        $field = Str::make('title');

        self::assertFalse($field->isHidden());
        self::assertFalse($field->isHiddenFor($this->guest(), null));
    }

    // ---- writeOnly(closure) -------------------------------------------------

    #[Test]
    public function writeOnlyClosureGatesOnTheRequest(): void
    {
        $field = Str::make('token')->writeOnly(
            static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertFalse($field->isWriteOnly());
        self::assertTrue($field->isWriteOnlyFor($this->guest()));
        self::assertFalse($field->isWriteOnlyFor($this->admin()));
    }

    #[Test]
    public function unconditionalWriteOnlyPrecedesAnyRequest(): void
    {
        $field = Str::make('password')->writeOnly();

        self::assertTrue($field->isWriteOnly());
        self::assertTrue($field->isWriteOnlyFor($this->admin()));
    }

    // ---- readOnly(closure) --------------------------------------------------

    #[Test]
    public function readOnlyClosureGatesBothContextsOnTheRequest(): void
    {
        $field = Str::make('slug')->readOnly(
            static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertFalse($field->isReadOnly(true));
        self::assertFalse($field->isReadOnly(false));

        self::assertTrue($field->isReadOnlyFor(true, $this->guest()));
        self::assertTrue($field->isReadOnlyFor(false, $this->guest()));
        self::assertFalse($field->isReadOnlyFor(true, $this->admin()));
        self::assertFalse($field->isReadOnlyFor(false, $this->admin()));
    }

    #[Test]
    public function readOnlyOnCreateClosureIsContextScoped(): void
    {
        $field = Str::make('a')->readOnlyOnCreate(
            static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertTrue($field->isReadOnlyFor(true, $this->guest()));
        // The create predicate must not leak into the update context.
        self::assertFalse($field->isReadOnlyFor(false, $this->guest()));
    }

    #[Test]
    public function readOnlyOnUpdateClosureIsContextScoped(): void
    {
        $field = Str::make('a')->readOnlyOnUpdate(
            static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertFalse($field->isReadOnlyFor(true, $this->guest()));
        self::assertTrue($field->isReadOnlyFor(false, $this->guest()));
    }

    #[Test]
    public function unconditionalReadOnlyPrecedesAnyRequest(): void
    {
        $field = Str::make('viewCount')->readOnly();

        self::assertTrue($field->isReadOnlyFor(true, $this->admin()));
        self::assertTrue($field->isReadOnlyFor(false, $this->admin()));
    }

    // ---- the contradiction guard (the only change is the matrix) ------------

    #[Test]
    public function unconditionalWriteOnlyAfterUnconditionalReadOnlyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be both write-only and read-only');

        Str::make('secret')->readOnly()->writeOnly();
    }

    #[Test]
    public function unconditionalReadOnlyAfterUnconditionalWriteOnlyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be both read-only and write-only');

        Str::make('secret')->writeOnly()->readOnly();
    }

    #[Test]
    public function aReadOnlyPredicateThenUnconditionalWriteOnlyDoesNotTripTheGuard(): void
    {
        // A read-only *predicate* defers the decision to request time, so it is
        // coherent alongside an unconditional write-only — the guard must not fire.
        $field = Str::make('secret')
            ->readOnly(static fn(JsonApiRequestInterface $request): bool => false)
            ->writeOnly();

        self::assertTrue($field->isWriteOnly());
        self::assertFalse($field->isReadOnly(true));
    }

    #[Test]
    public function aWriteOnlyPredicateThenUnconditionalReadOnlyDoesNotTripTheGuard(): void
    {
        $field = Str::make('secret')
            ->writeOnly(static fn(JsonApiRequestInterface $request): bool => false)
            ->readOnly();

        self::assertTrue($field->isReadOnly(true));
        self::assertFalse($field->isWriteOnly());
    }

    #[Test]
    public function twoPredicatesNeverTripTheGuard(): void
    {
        $field = Str::make('secret')
            ->readOnly(static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin')
            ->writeOnly(static fn(JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') === 'admin');

        // Each resolver stays individually coherent.
        self::assertTrue($field->isReadOnlyFor(true, $this->guest()));
        self::assertTrue($field->isWriteOnlyFor($this->admin()));
    }

    // ---- relation cannotReplace/Remove/Add/BeIncluded (inverted) -----------

    #[Test]
    public function cannotReplaceClosureInvertsAgainstTheRequest(): void
    {
        $relation = BelongsTo::make('owner', 'users')->cannotReplace(
            static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        // Static getter stays permissive (not unconditionally prohibited).
        self::assertTrue($relation->allowsReplace());

        // allowsReplaceFor = NOT(prohibited): guest prohibited, admin allowed.
        self::assertFalse($relation->allowsReplaceFor($this->guest(), null));
        self::assertTrue($relation->allowsReplaceFor($this->admin(), null));
    }

    #[Test]
    public function cannotRemoveClosureInvertsAgainstTheRequest(): void
    {
        $relation = HasMany::make('tags', 'tags')->cannotRemove(
            static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertTrue($relation->allowsRemove());
        self::assertFalse($relation->allowsRemoveFor($this->guest(), null));
        self::assertTrue($relation->allowsRemoveFor($this->admin(), null));
    }

    #[Test]
    public function cannotAddClosureInvertsAgainstTheRequest(): void
    {
        $relation = HasMany::make('tags', 'tags')->cannotAdd(
            static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertTrue($relation->allowsAdd());
        self::assertFalse($relation->allowsAddFor($this->guest(), null));
        self::assertTrue($relation->allowsAddFor($this->admin(), null));
    }

    #[Test]
    public function cannotBeIncludedClosureInvertsAgainstTheRequest(): void
    {
        $relation = BelongsTo::make('secret', 'secrets')->cannotBeIncluded(
            static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
        );

        self::assertTrue($relation->isIncludable());
        self::assertFalse($relation->isIncludableFor($this->guest(), null));
        self::assertTrue($relation->isIncludableFor($this->admin(), null));
    }

    #[Test]
    public function unconditionalRelationProhibitionPrecedesAnyRequest(): void
    {
        $relation = BelongsTo::make('owner', 'users')->cannotReplace();

        self::assertFalse($relation->allowsReplace());
        // Unconditional prohibition denies regardless of the caller.
        self::assertFalse($relation->allowsReplaceFor($this->admin(), null));
        self::assertFalse($relation->allowsReplaceFor($this->guest(), null));
    }

    #[Test]
    public function relationResolversDefaultPermissiveWithNoDeclaration(): void
    {
        $relation = HasMany::make('tags', 'tags');

        self::assertTrue($relation->allowsReplaceFor($this->guest(), null));
        self::assertTrue($relation->allowsRemoveFor($this->guest(), null));
        self::assertTrue($relation->allowsAddFor($this->guest(), null));
        self::assertTrue($relation->isIncludableFor($this->guest(), null));
    }

    private function admin(): StubJsonApiRequest
    {
        return StubJsonApiRequest::create([], ['X-Role' => 'admin']);
    }

    private function guest(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}
