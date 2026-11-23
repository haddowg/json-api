<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\DateMessage;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * The date field a filter's values are read as, so an adapter refuses a value that is not
 * that date with the field's {@see DateMessage}, as it does a bad date attribute.
 *
 * A {@see Where} comparing with `=`, `!=`, `<`, `<=`, `>` or `>=`, and a {@see WhereIn}
 * or {@see WhereNotIn}, takes the date its column holds when it declares no value
 * constraints of its own and is not triggered by presence alone: the field a
 * `->through()` path resolves to, or the one field of `$fields` backing its column. A
 * substring match, a filter with its own constraints and anything else reads no date.
 */
final class DateFilterValue
{
    private const array COMPARISONS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    private function __construct() {}

    /**
     * The date field `$filter`'s values are read as, or `null` when they are not dates.
     *
     * @param list<FieldInterface> $fields the filtered type's fields
     */
    public static function fieldFor(FilterInterface $filter, array $fields): ?DateTime
    {
        if (!$filter instanceof Where && !$filter instanceof WhereIn && !$filter instanceof WhereNotIn) {
            return null;
        }
        if (($filter instanceof Where && !\in_array($filter->operator, self::COMPARISONS, true))
            || $filter->constraints() !== []
            || ($filter instanceof PresenceTriggeredFilter && $filter->isPresenceTriggered())) {
            return null;
        }

        if ($filter->throughRelations() !== null) {
            $leaf = $filter->resolvedThrough()?->leaf;

            return $leaf instanceof DateTime ? $leaf : null;
        }

        $match = null;
        foreach ($fields as $field) {
            if ($field->column() !== $filter->targetColumn() || $field->relatedVia() !== null) {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = $field;
        }

        return $match instanceof DateTime ? $match : null;
    }

    /**
     * The message refusing one value or list member, `null` when `$field` takes it.
     */
    public static function invalid(DateTime $field, mixed $value): ?DateMessage
    {
        return $field->tryParseWire($value) === null ? $field->dateMessage() : null;
    }
}
