<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class CodeGeneratorService
{
    /**
     * Derive a short, unique, uppercase code from a name — e.g. "Nellore" -> "NLR".
     *
     * Algorithm:
     *   1. Strip anything that isn't a letter, uppercase what's left.
     *   2. Take the first 3 letters as the base candidate.
     *   3. If that's already taken on the given model/column, append 1, 2, 3...
     *      until a free one is found (e.g. "NLR2", "NLR3").
     *
     * $modelClass and $column let this be reused for both Franchise::code and
     * Zone::code without duplicating the uniqueness-check logic.
     */
    public function generate(string $name, string $modelClass, string $column = 'code'): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));

        if (strlen($letters) === 0) {
            $letters = 'ZZZ'; // fallback for names with no letters at all (shouldn't normally happen)
        }

        $base = substr(str_pad($letters, 3, 'X'), 0, 3);

        $candidate = $base;
        $suffix = 1;

        while ($modelClass::where($column, $candidate)->exists()) {
            $suffix++;
            $candidate = $base . $suffix;
        }

        return $candidate;
    }
}
