<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Engines\Expressions;

/**
 * A field path resolver for the `subject.*` / `context.*` / `user.*` / `instance.*`
 * paths used in condition JSON.
 *
 * Pure read access; throws if the path is unknown (no exception swallowing).
 */
final class Field
{
    /**
     * Resolve `$path` against `$context` and return the leaf value (or null).
     *
     * @param  string  $path
     * @param  array<string, mixed>  $context
     */
    public static function resolve(string $path, array $context): mixed
    {
        if ($path === '' || ! str_contains($path, '.')) {
            return $context[$path] ?? null;
        }

        [$namespace, $rest] = explode('.', $path, 2);
        $bag = $context[$namespace] ?? [];

        if (! is_array($bag)) {
            return null;
        }

        $segments = explode('.', $rest);
        $cursor = $bag;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
