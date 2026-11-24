<?php

namespace App\Support;

/** Formats paise for display (Indian grouping, lakh / crore). Storage stays in paise. */
final class Money
{
    /** ₹12,34,567 */
    public static function inr(int $paise): string
    {
        $rupees = intdiv($paise, 100);
        $neg = $rupees < 0;
        $s = (string) abs($rupees);
        if (strlen($s) > 3) {
            $last3 = substr($s, -3);
            $rest = substr($s, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $s = $rest.','.$last3;
        }

        return ($neg ? '−' : '').'₹'.$s;
    }

    /** ₹1.25 cr / ₹48.6 L / ₹9,500 */
    public static function short(int $paise): string
    {
        $rupees = $paise / 100;
        if (abs($rupees) >= 1_00_00_000) {
            return '₹'.self::trim($rupees / 1_00_00_000).' cr';
        }
        if (abs($rupees) >= 1_00_000) {
            return '₹'.self::trim($rupees / 1_00_000).' L';
        }

        return self::inr($paise);
    }

    public static function pct(?float $value, int $decimals = 0): string
    {
        return $value === null ? '—' : number_format($value, $decimals).'%';
    }

    private static function trim(float $v): string
    {
        return rtrim(rtrim(number_format($v, $v >= 100 ? 0 : 2, '.', ''), '0'), '.');
    }
}
