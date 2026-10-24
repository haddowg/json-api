<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresSingularName;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * How generated prose names a resource type: the type rendered as words, in the plural
 * for a collection and the singular, with its indefinite article, for one resource.
 *
 * The type name splits into lower-case words at `-`, `_`, spaces and camelCase
 * boundaries (`export-jobs` and `exportJobs` both read "export jobs"); an all-caps word
 * keeps its case. The singular inflects the last word only, unless the metadata declares
 * one ({@see DeclaresSingularName}), which is used as written.
 *
 * @internal
 */
final readonly class TypeNoun
{
    /**
     * Words that start with a vowel letter but a consonant sound, so take "a".
     */
    private const string CONSONANT_SOUND = '/^(?:uni(?![nmdl])|us[aeiu]|uti|ure|eu|ewe|one(?![a-z])|once)/';

    /**
     * Words that start with a silent `h`, so take "an".
     */
    private const string SILENT_H = '/^(?:hour|honest|honou?r|heir)/';

    /**
     * Letters whose spoken name starts with a vowel sound, for an all-caps acronym
     * ("an SDK", "a URL").
     */
    private const string VOWEL_LETTER_NAMES = 'AEFHILMNORSX';

    private function __construct(
        public string $singular,
        public string $plural,
    ) {}

    public static function of(TypeMetadataInterface $type): self
    {
        return self::fromType($type->type(), $type instanceof DeclaresSingularName ? $type->singularName() : null);
    }

    public static function fromType(string $type, ?string $singular = null): self
    {
        $words = self::words($type);
        if ($words === []) {
            $words = [$type];
        }
        $plural = \implode(' ', $words);

        if ($singular === null || \trim($singular) === '') {
            $last = \array_pop($words);
            $words[] = self::isAcronym($last) ? $last : self::inflector()->singularize($last);
            $singular = \implode(' ', $words);
        }

        return new self(\trim($singular), $plural);
    }

    /**
     * The singular with its indefinite article: "an advertiser", "a user", "an hour".
     */
    public function withArticle(): string
    {
        return self::article($this->singular) . ' ' . $this->singular;
    }

    /**
     * "a" or "an" for a noun phrase, chosen from the sound of its first word: a vowel
     * letter takes "an" unless the word starts with a consonant sound (`uni…`, `use…`,
     * `eu…`, `one`), a silent `h` takes "an", and an all-caps acronym follows the
     * spoken name of its first letter.
     */
    public static function article(string $noun): string
    {
        $first = \strtok(\trim($noun), ' -') ?: '';
        if ($first === '') {
            return 'a';
        }

        if (self::isAcronym($first)) {
            return \str_contains(self::VOWEL_LETTER_NAMES, $first[0]) ? 'an' : 'a';
        }

        $word = \strtolower($first);
        if (\preg_match(self::SILENT_H, $word) === 1) {
            return 'an';
        }
        if (\preg_match(self::CONSONANT_SOUND, $word) === 1) {
            return 'a';
        }

        return \str_contains('aeiou', $word[0]) ? 'an' : 'a';
    }

    /**
     * @return list<string>
     */
    private static function words(string $type): array
    {
        // Split camelCase (`exportJobs`) and acronym runs (`APIKeys`) before the separators.
        $spaced = \preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $type) ?? $type;
        $words = [];
        foreach (\preg_split('/[\s_\-]+/', $spaced) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $words[] = self::isAcronym($word) ? $word : \strtolower($word);
        }

        return $words;
    }

    private static function isAcronym(string $word): bool
    {
        return \strlen($word) > 1 && \preg_match('/^[A-Z0-9]+$/', $word) === 1 && \preg_match('/[A-Z]/', $word) === 1;
    }

    private static function inflector(): Inflector
    {
        static $inflector = null;

        return $inflector ??= InflectorFactory::create()->build();
    }
}
