<?php

declare(strict_types=1);

namespace HFlow\LaravelWorkflow\Concerns;

/**
 * Helper for declaring enum column casts on Eloquent models.
 *
 * Models should call `CastsEnums::castsFor($map)` in their `$casts` array,
 * or use the lower-level `casts()` method override. This is a builder
 * helper, not a trait.
 */
final class CastsEnums
{
    /**
     * @param  array<string, class-string<\BackedEnum>>  $enums
     * @return array<string, string>
     */
    public static function castsFor(array $enums): array
    {
        $out = [];
        foreach ($enums as $column => $enumClass) {
            $out[$column] = $enumClass;
        }

        return $out;
    }
}
