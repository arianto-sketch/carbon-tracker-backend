<?php

namespace App\Support;

/** Format angka untuk email, sama dengan tampilan frontend (src/utils/formatters.ts) */
class Format
{
    /** "462.00 kg CO₂e" atau "1.23 ton CO₂e" */
    public static function co2(float $kg): string
    {
        return $kg >= 1000
            ? number_format($kg / 1000, 2, '.', '').' ton CO₂e'
            : number_format($kg, 2, '.', '').' kg CO₂e';
    }

    /** Perubahan persen bertanda, mis. "+100,0%" atau "-25,5%" */
    public static function change(float $pct): string
    {
        // Dibulatkan dulu: perubahan sangat kecil tampil "0,0%", bukan "-0,0%"
        $shown = round($pct, 1);
        if ($shown == 0) {
            $shown = 0.0;
        }

        return ($shown > 0 ? '+' : '').number_format($shown, 1, ',', '.').'%';
    }
}
