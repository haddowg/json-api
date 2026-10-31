# Generated prose is inflected by `doctrine/inflector`

Generated summaries and descriptions that mean one resource use the type's English singular with a matching article ("an export job"), so core takes `doctrine/inflector` as a runtime dependency: it has no dependencies of its own, returns one answer per word, and most Symfony and Laravel apps already install it. A type whose inflection reads badly declares its own noun.

## Considered options

`symfony/string`'s inflector (several candidates per word, plus polyfills) and a hand-written suffix list (fails on exactly the irregulars a reader notices).
