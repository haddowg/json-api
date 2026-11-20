<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so a union whose discriminator is bound to an enum takes only
 * that enum's cases, and a `variants()` match missing a case fails the `composer phpstan`
 * run.
 */
final class OneOfEnumDiscriminatorTypes
{
    /**
     * @return list<FieldBuilderInterface>
     */
    public static function unions(): array
    {
        return [
            OneOf::make('block')->discriminator('type', StaticBlockType::class)
                ->variants(static fn(StaticBlockType $type): ?Obj => match ($type) {
                    StaticBlockType::Text => Obj::make('text')->fields(Str::make('title'), Str::make('body'))->selectableKeys(pattern: 'title|body')->build(),
                    StaticBlockType::Image => Obj::make('image')->fields(Str::make('url'))->build(),
                    StaticBlockType::Divider => null,
                }),
            OneOf::make('block')->discriminator('type', StaticBlockType::class)
                ->variant(StaticBlockType::Text, Obj::make('text')->fields(Str::make('title'))->selectableKeys(pattern: 'title'))
                ->variant(StaticBlockType::Image, Str::make('url'))
                ->variant(StaticBlockType::Divider),
            OneOf::make('block')->discriminator('type')
                ->variant('text', Str::make('title'))
                ->variant('image', Obj::make('image')->fields(Str::make('url'))->selectableKeys(pattern: 'url')),
            OneOf::make('block')->discriminator('type', StaticBlockType::class)
                // @phpstan-ignore argument.type (a variant of an enum-bound union is named by one of its cases)
                ->variant(StaticOtherType::Other, Str::make('x')),
            OneOf::make('block')->discriminator('type', StaticBlockType::class)
                // @phpstan-ignore argument.type (a variant of an enum-bound union is named by one of its cases)
                ->variant('text', Str::make('x')),
            OneOf::make('block')->discriminator('type', StaticBlockType::class)
                ->variants(static fn(StaticBlockType $type): ?Obj => match ($type) { // @phpstan-ignore match.unhandled (every case needs a variant)
                    StaticBlockType::Text => null,
                    StaticBlockType::Image => null,
                }),
        ];
    }
}
