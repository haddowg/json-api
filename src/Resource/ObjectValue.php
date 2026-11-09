<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Obj;

/**
 * One {@see Obj} value in a write body that an adapter validates beyond its declared
 * children, as {@see ObjectValues} finds it: its member count against the object's
 * `minProperties()` / `maxProperties()`, and each undeclared member of an open object
 * against {@see Obj::additionalProperties()}.
 */
final readonly class ObjectValue
{
    /**
     * @param string                  $pointer     the JSON Pointer of the object, as `/data/attributes/rows/3`
     * @param array<array-key, mixed> $value       the object as the request sent it
     * @param array<string, mixed>    $additional  the undeclared members the write takes through `$memberField`,
     *                                              empty when the object is closed or the field is read-only for it
     * @param FieldInterface|null     $memberField the field validating each of `$additional`
     */
    public function __construct(
        public string $pointer,
        public Obj $shape,
        public array $value,
        public array $additional,
        public ?FieldInterface $memberField,
    ) {}

    /**
     * The JSON Pointer of one of the object's members, its key escaped.
     */
    public function memberPointer(string $key): string
    {
        return $this->pointer . '/' . JsonObject::pointerSegment($key);
    }

    /**
     * The `minProperties()` / `maxProperties()` constraints of the object that apply to
     * the write.
     *
     * @return list<MinProperties|MaxProperties>
     */
    public function memberCounts(bool $creating): array
    {
        $counts = [];
        foreach ($this->shape->constraints() as $constraint) {
            if (($constraint instanceof MinProperties || $constraint instanceof MaxProperties) && $constraint->context()->appliesTo($creating)) {
                $counts[] = $constraint;
            }
        }

        return $counts;
    }
}
