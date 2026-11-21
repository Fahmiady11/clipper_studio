<?php

namespace App\Support;

class FilterExpr
{
    /**
     * Sum of ffmpeg expression terms, nested as a balanced tree: a+(b+c), (a+b)+(c+d).
     * ffmpeg parses a flat a+b+c+... into a chain one level deeper per term, and
     * builds with the AVExpr depth check (Debian 12's 5.1.9, the 5.1 and 8.0 branches,
     * master) refuse anything over 100 levels: a crop whose x has ~95 face-track
     * pieces then fails to configure ("Failed to configure input pad", nothing else).
     *
     * @param  list<string>  $terms
     */
    public static function sum(array $terms): string
    {
        $n = count($terms);
        if ($n < 2) {
            return $terms[0] ?? '0';
        }
        $half = intdiv($n, 2);
        $group = fn (array $t) => count($t) === 1 ? $t[0] : '('.self::sum($t).')';

        return $group(array_slice($terms, 0, $half)).'+'.$group(array_slice($terms, $half));
    }
}
