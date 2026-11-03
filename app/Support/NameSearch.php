<?php

namespace App\Support;

/**
 * Lets list searches match a person's FULL name, not just one name column.
 *
 * Usage inside a query chain (the $needle is the already-wrapped "%term%" string):
 *
 *   ->orWhere('tbl_guest.last_name', 'like', $needle)
 *   ->orWhereRaw(
 *       NameSearch::sql('tbl_guest.first_name', 'tbl_guest.middle_name', 'tbl_guest.last_name'),
 *       NameSearch::bindings($needle),
 *   )
 *
 * Matches "Juan Cruz", "Juan Dela Cruz" (with middle name) and "Cruz Juan" / "Cruz, Juan".
 */
class NameSearch
{
    public static function sql(string $first, ?string $middle, string $last): string
    {
        $mid = $middle !== null ? ", NULLIF({$middle}, '')" : '';

        return "(CONCAT_WS(' ', {$first}{$mid}, {$last}) LIKE ?"
            ." OR CONCAT_WS(' ', {$first}, {$last}) LIKE ?"
            ." OR CONCAT_WS(' ', {$last}, {$first}{$mid}) LIKE ?)";
    }

    /** @return array<int, string> three bindings, one per LIKE in sql() */
    public static function bindings(string $needle): array
    {
        // Collapse repeated spaces and drop commas so "Cruz,  Juan" matches "Cruz Juan".
        $needle = trim((string) preg_replace('/\s+/u', ' ', str_replace(',', ' ', $needle)));

        return [$needle, $needle, $needle];
    }
}
