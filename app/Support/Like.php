<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian LIKE yang memperlakukan % dan _ dari input sebagai karakter biasa.
 * Memakai ESCAPE '!' (bukan backslash) supaya sintaksnya sama di MySQL dan SQLite.
 */
final class Like
{
    /** @param  array<int, string>  $columns */
    public static function anyContains(Builder $query, array $columns, string $term): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhereRaw($query->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
