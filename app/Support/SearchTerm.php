<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Case- and diacritic-insensitive matching for Turkish text.
 *
 * Postgres ILIKE (and SQLite's LIKE) fold case by ASCII rules, so "Irmak" lowercases to "irmak"
 * and a search for "ırmak" never matches — the dotless ı and the dotted i are different code
 * points. Folding both the stored value and the term to a plain ASCII form makes every spelling
 * of a name find every other one, which is what a receptionist typing fast expects.
 *
 * Column names come from code, never from request input.
 */
class SearchTerm
{
    /** Turkish letters folded to their ASCII counterpart, both cases. */
    private const FOLD = [
        'İ' => 'i',
        'I' => 'i',
        'ı' => 'i',
        'Ş' => 's',
        'ş' => 's',
        'Ğ' => 'g',
        'ğ' => 'g',
        'Ü' => 'u',
        'ü' => 'u',
        'Ö' => 'o',
        'ö' => 'o',
        'Ç' => 'c',
        'ç' => 'c',
    ];

    /**
     * Fold a user-typed term the same way the column expression folds stored values.
     */
    public static function normalize(string $term): string
    {
        return mb_strtolower(strtr($term, self::FOLD), 'UTF-8');
    }

    /**
     * The folded form of a column, or of several columns joined by a space (so "Mehmet Yılmaz"
     * matches a first_name/last_name pair that no single column contains).
     *
     * `||` is the string concatenation operator in both Postgres and SQLite, and replace()/lower()
     * exist in both — the expression stays driver-agnostic, which the sqlite test connection needs.
     *
     * @param  string|list<string>  $columns
     */
    public static function column(string|array $columns): Expression
    {
        $grammar = DB::getQueryGrammar();

        $sql = is_array($columns)
            ? implode(" || ' ' || ", array_map(static fn (string $c): string => $grammar->wrap($c), $columns))
            : $grammar->wrap($columns);

        foreach (self::FOLD as $from => $to) {
            $sql = "replace({$sql}, '{$from}', '{$to}')";
        }

        return DB::raw("lower({$sql})");
    }
}
