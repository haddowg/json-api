<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * The comparison a {@see CompareField} asserts between the field under validation
 * and another field's value. The symbol backing each case reads as
 * `<this field> <operator> <other field>`.
 */
enum Comparison: string
{
    case EqualTo = '=';
    case NotEqualTo = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
}
