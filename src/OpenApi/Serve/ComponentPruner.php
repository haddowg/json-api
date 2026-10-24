<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

/**
 * Drops what serve-time decorators orphaned from an OpenAPI document.
 *
 * It works on the JSON form (`OpenApi::toJson()`), so it never rebuilds value objects.
 * Only a component the {@see PruneBaseline} saw reachable from the undecorated document's
 * paths is a candidate; everything else in `components` (a component nothing referenced to
 * begin with, or one a decorator added) is kept and treated as a root, so a kept component
 * never ends up with a dangling `$ref`. Path items left with no operation and tag
 * definitions whose operations all went are dropped too. Security schemes are left alone:
 * they are referenced by name, not `$ref`, and a document-level default may name them.
 */
final class ComponentPruner
{
    private const string PREFIX = '#/components/';

    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const array PRUNABLE = ['schemas', 'responses', 'parameters', 'examples', 'requestBodies', 'headers', 'links', 'callbacks', 'pathItems'];

    /**
     * Records what `$document` reaches from its paths, before any decorator runs.
     */
    public static function baseline(\stdClass $document): PruneBaseline
    {
        $reachable = [];
        $queue = [];
        foreach (\get_object_vars($document) as $member => $value) {
            if ($member !== 'components') {
                self::collect($value, $reachable, $queue);
            }
        }
        self::drain($document, $reachable, $queue);

        return new PruneBaseline($reachable, self::operationTags($document));
    }

    /**
     * Prunes `$document` in place and returns it.
     */
    public static function prune(\stdClass $document, PruneBaseline $baseline): \stdClass
    {
        self::dropEmptyPathItems($document);

        $reachable = [];
        $queue = [];
        foreach (\get_object_vars($document) as $member => $value) {
            if ($member !== 'components') {
                self::collect($value, $reachable, $queue);
            }
        }

        // Every component that was not a candidate is a root.
        $components = $document->components ?? null;
        if ($components instanceof \stdClass) {
            foreach (\get_object_vars($components) as $section => $entries) {
                if (!$entries instanceof \stdClass) {
                    continue;
                }
                foreach (\array_keys(\get_object_vars($entries)) as $name) {
                    $ref = self::ref((string) $section, (string) $name);
                    if (!isset($baseline->reachable[$ref]) && !isset($reachable[$ref])) {
                        $reachable[$ref] = true;
                        $queue[] = $ref;
                    }
                }
            }
        }
        self::drain($document, $reachable, $queue);

        if ($components instanceof \stdClass) {
            foreach (self::PRUNABLE as $section) {
                $entries = $components->{$section} ?? null;
                if (!$entries instanceof \stdClass) {
                    continue;
                }
                foreach (\array_keys(\get_object_vars($entries)) as $name) {
                    $ref = self::ref($section, (string) $name);
                    if (isset($baseline->reachable[$ref]) && !isset($reachable[$ref])) {
                        unset($entries->{$name});
                    }
                }
                if (\get_object_vars($entries) === []) {
                    unset($components->{$section});
                }
            }
        }

        self::pruneTags($document, $baseline);

        return $document;
    }

    /**
     * @param array<string, true> $reachable
     * @param list<string>        $queue
     */
    private static function drain(\stdClass $document, array &$reachable, array &$queue): void
    {
        while ($queue !== []) {
            $ref = \array_pop($queue);
            $target = self::resolve($document, $ref);
            if ($target !== null) {
                self::collect($target, $reachable, $queue);
            }
        }
    }

    /**
     * Records every `#/components/...` reference under `$node`, reduced to the component
     * it lands in, and queues the ones not seen before.
     *
     * @param array<string, true> $reachable
     * @param list<string>        $queue
     */
    private static function collect(mixed $node, array &$reachable, array &$queue): void
    {
        if (!\is_array($node) && !$node instanceof \stdClass) {
            return;
        }

        foreach (($node instanceof \stdClass ? \get_object_vars($node) : $node) as $key => $value) {
            if (\is_string($value)) {
                if ($key === '$ref') {
                    self::mark($value, $reachable, $queue);
                }

                continue;
            }
            if ($key === 'mapping' && $value instanceof \stdClass) {
                // A discriminator mapping names its targets as plain strings.
                foreach (\get_object_vars($value) as $target) {
                    if (\is_string($target)) {
                        self::mark($target, $reachable, $queue);
                    }
                }
            }
            self::collect($value, $reachable, $queue);
        }
    }

    /**
     * @param array<string, true> $reachable
     * @param list<string>        $queue
     */
    private static function mark(string $pointer, array &$reachable, array &$queue): void
    {
        if (!\str_starts_with($pointer, self::PREFIX)) {
            return;
        }

        $segments = \explode('/', \substr($pointer, \strlen(self::PREFIX)), 3);
        if (\count($segments) < 2) {
            return;
        }

        $ref = self::PREFIX . $segments[0] . '/' . $segments[1];
        if (!isset($reachable[$ref])) {
            $reachable[$ref] = true;
            $queue[] = $ref;
        }
    }

    private static function resolve(\stdClass $document, string $ref): mixed
    {
        [$section, $name] = \explode('/', \substr($ref, \strlen(self::PREFIX)), 2);
        $entries = $document->components->{$section} ?? null;
        if (!$entries instanceof \stdClass) {
            return null;
        }

        return $entries->{self::unescape($name)} ?? null;
    }

    private static function ref(string $section, string $name): string
    {
        return self::PREFIX . $section . '/' . \str_replace(['~', '/'], ['~0', '~1'], $name);
    }

    private static function unescape(string $segment): string
    {
        return \str_replace(['~1', '~0'], ['/', '~'], $segment);
    }

    private static function dropEmptyPathItems(\stdClass $document): void
    {
        $paths = $document->paths ?? null;
        if (!$paths instanceof \stdClass) {
            return;
        }

        foreach (\array_keys(\get_object_vars($paths)) as $path) {
            $item = $paths->{$path};
            if (!$item instanceof \stdClass || isset($item->{'$ref'})) {
                continue;
            }
            foreach (self::METHODS as $method) {
                if (isset($item->{$method})) {
                    continue 2;
                }
            }
            unset($paths->{$path});
        }

        if (\get_object_vars($paths) === []) {
            unset($document->paths);
        }
    }

    /**
     * @return array<string, true>
     */
    private static function operationTags(\stdClass $document): array
    {
        $tags = [];
        $paths = $document->paths ?? null;
        if (!$paths instanceof \stdClass) {
            return $tags;
        }

        foreach (\get_object_vars($paths) as $item) {
            if (!$item instanceof \stdClass) {
                continue;
            }
            foreach (self::METHODS as $method) {
                $operation = $item->{$method} ?? null;
                if (!$operation instanceof \stdClass || !\is_array($operation->tags ?? null)) {
                    continue;
                }
                foreach ($operation->tags as $tag) {
                    if (\is_string($tag)) {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        return $tags;
    }

    private static function pruneTags(\stdClass $document, PruneBaseline $baseline): void
    {
        if (!\is_array($document->tags ?? null)) {
            return;
        }

        $inUse = self::operationTags($document);
        $document->tags = \array_values(\array_filter(
            $document->tags,
            static function (mixed $tag) use ($baseline, $inUse): bool {
                $name = $tag instanceof \stdClass ? ($tag->name ?? null) : null;

                return !\is_string($name) || !isset($baseline->operationTags[$name]) || isset($inUse[$name]);
            },
        ));

        if ($document->tags === []) {
            unset($document->tags);
        }
    }
}
