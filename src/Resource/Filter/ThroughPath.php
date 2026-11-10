<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A traversal filter's dotted path resolved against the relation graph of the type it
 * filters, by {@see ThroughPathResolver}. It tells a data layer what the path ends on.
 *
 * The {@see $segments} are the path's storage segments, exactly as declared: every one
 * but the last a relationship, the last either a field or, when {@see endsOnRelation()},
 * a monomorphic relationship whose related resource's id the filter compares. For a
 * relationship leaf, {@see $relatedType} names the related type and {@see $relatedId}
 * is its id field, through whose encoder {@see storageValue()} decodes a wire id into
 * the storage key the related row holds.
 */
final readonly class ThroughPath
{
    /**
     * @param non-empty-list<string> $segments
     */
    public function __construct(
        public string $path,
        public array $segments,
        public ?RelationInterface $leafRelation = null,
        public ?string $relatedType = null,
        public ?Id $relatedId = null,
    ) {}

    /**
     * A path that ends on a field, compared as it always was.
     */
    public static function toField(string $path): self
    {
        return new self($path, self::split($path));
    }

    /**
     * Whether the last segment is a relationship, so the filter compares the related
     * resource's id rather than a field.
     *
     * @phpstan-assert-if-true RelationInterface $this->leafRelation
     * @phpstan-assert-if-true string $this->relatedType
     */
    public function endsOnRelation(): bool
    {
        return $this->leafRelation !== null;
    }

    /**
     * The member of a related row that holds its storage key, or `null` when the path
     * ends on a field.
     */
    public function relatedIdColumn(): ?string
    {
        if (!$this->endsOnRelation()) {
            return null;
        }

        return $this->relatedId?->column() ?? $this->relatedId?->name() ?? 'id';
    }

    /**
     * The storage key a wire id names: decoded through the related type's id encoder
     * when it declares one, and the wire id unchanged when it does not. A path that ends
     * on a field returns the value unchanged.
     *
     * @throws FilterValueInvalid when the related type's encoder cannot decode the id, or the value is not a single id
     */
    public function storageValue(string $key, mixed $value): mixed
    {
        if (!$this->endsOnRelation()) {
            return $value;
        }

        if (!\is_string($value) && !\is_int($value)) {
            throw new FilterValueInvalid($key, [\sprintf('Each value must be the id of a "%s" resource.', $this->relatedType)]);
        }

        $encoder = $this->relatedId?->encoder();
        if ($encoder === null) {
            return $value;
        }

        return $encoder->decode((string) $value)
            ?? throw new FilterValueInvalid($key, [\sprintf('"%s" is not the id of a "%s" resource.', $value, $this->relatedType)]);
    }

    /**
     * The constraints a relationship leaf's values default to when the filter declares
     * none: the related id's declared format, so a numeric id documents and validates as
     * an integer and a UUID id as a UUID. Empty for a field leaf, or a related id that
     * declares no format.
     *
     * @return list<ConstraintInterface>
     */
    public function idConstraints(): array
    {
        if (!$this->endsOnRelation() || $this->relatedId === null) {
            return [];
        }

        $constraints = [];
        foreach ($this->relatedId->constraints() as $constraint) {
            $default = match (true) {
                $constraint instanceof UuidFormat => new UuidFormat($constraint->version),
                $constraint instanceof UlidFormat => new UlidFormat(),
                $constraint instanceof Pattern && $constraint->regex === '^' . Id::NUMERIC_FORMAT_PATTERN . '$' => new Pattern($constraint->regex, documentsAs: 'integer'),
                $constraint instanceof Pattern => new Pattern($constraint->regex),
                default => null,
            };
            if ($default !== null) {
                $constraints[] = $default;
            }
        }

        return $constraints;
    }

    /**
     * @return non-empty-list<string>
     */
    public static function split(string $path): array
    {
        return \explode('.', $path);
    }
}
