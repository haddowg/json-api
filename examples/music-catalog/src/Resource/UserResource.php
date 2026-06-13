<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `users` resource. Demonstrates several format-subtype fields ({@see Email},
 * {@see Ip}), a dynamic-key {@see ArrayHash}, a write-only `password` (rendered as
 * null but still hydrated), and the validation-composition trio on
 * `passwordConfirm`: an {@see \haddowg\JsonApi\Resource\Constraint\AtLeastOneOf}
 * of two alternatives, a conditional {@see \haddowg\JsonApi\Resource\Constraint\When}
 * rule, and a **non-directional** equality
 * {@see \haddowg\JsonApi\Resource\Constraint\CompareField} (`passwordConfirm`
 * `EqualTo` `password`) — the equality counterpart to the album date pair's
 * directional `GreaterThan` comparison.
 */
final class UserResource extends AbstractResource
{
    public static string $type = 'users';

    public function fields(): array
    {
        return [
            Id::make(),
            // Email::make() pre-attaches a (lax) EmailFormat; ->strict()
            // RECONCILES that to a single strict EmailFormat rather than stacking
            // a second constraint.
            Email::make('email')->required()->strict(),
            Str::make('displayName')->required(),
            Date::make('birthDate')->nullable(),
            // A dynamic-key JSON object (vs Map's declared columns); sorted by key
            // on serialization for a stable wire shape.
            ArrayHash::make('preferences')->minProperties(0)->maxProperties(20)->sortKeys(),
            Ip::make('lastSeenIp')->nullable(),
            // Write-only: a null read hook renders nothing on serialize while the
            // field still hydrates from the request (so it is NOT hidden(), which
            // would exclude it from hydration too).
            Str::make('password')->serializeUsing(fn(): ?string => null),
            // Computed (no backing column): validated but never persisted. Carries
            // the composition demos — an AtLeastOneOf alternative, a When rule, and
            // the equality CompareField against `password`.
            Str::make('passwordConfirm')
                ->computed()
                ->serializeUsing(fn(): ?string => null)
                ->atLeastOneOf(
                    new MinLength(8),
                    new Pattern('^.*[0-9].*$'),
                )
                ->when(
                    static fn(mixed $value): bool => $value !== null && $value !== '',
                    static function (Str $field): void {
                        $field->minLength(8);
                    },
                )
                ->compareWith('password', Comparison::EqualTo),

            // Default relation reader: `playlists` reads $user->playlists (a
            // list<Playlist>) and `library` reads $user->library (a Library, or null).
            HasMany::make('playlists')->type('playlists'),
            HasOne::make('library')->type('libraries'),
        ];
    }
}
