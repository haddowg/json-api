# Generated prose names one resource in the singular, inflected by `doctrine/inflector`

The generated summaries and descriptions built their sentences around the plural type name, so a fetch read "Fetch a advertisers". Prose that means one resource now uses the type's English singular with an "a"/"an" chosen from its first sound, and the type renders as words (`export-jobs` reads "an export job"); a type whose inflection reads badly declares its own noun through `AbstractResource::getSingularName()`, surfaced to the projector by the opt-in `DeclaresSingularName` metadata interface so `TypeMetadataInterface` keeps its shape. Core takes `doctrine/inflector` as a runtime dependency for the inflection: it has no dependencies of its own, returns one answer per word, and is already installed by most Symfony and Laravel applications.

## Considered options

- `symfony/string`'s `EnglishInflector`. It returns several candidates for many words (`images` gives `imag` and `image`) and brings four polyfills with it.
- A hand-written suffix rule list in core. Irregulars and uncountables are where it would fail, and those are the cases a reader notices.
- An article override. The vowel-letter rule with its short exception list covers ordinary nouns and acronyms, and an author who needs a different article can phrase the singular to suit it.
