<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The mutation a relationship-endpoint request applies to a to-many relationship,
 * derived from the JSON:API verb:
 *
 * - {@see Mode::Replace} — `PATCH /{type}/{id}/relationships/{name}`: set the whole
 *   relationship to the supplied linkage (also the to-one path and the
 *   whole-resource-body baseline).
 * - {@see Mode::Add} — `POST /{type}/{id}/relationships/{name}`: append the supplied
 *   linkage to the existing set (to-many only).
 * - {@see Mode::Remove} — `DELETE /{type}/{id}/relationships/{name}`: subtract the
 *   supplied linkage from the existing set (to-many only).
 */
enum Mode
{
    case Replace;
    case Add;
    case Remove;
}
