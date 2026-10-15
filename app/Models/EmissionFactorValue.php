<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nilai faktor emisi yang berlaku sejak valid_from sampai periode berikutnya dimulai.
 *
 * @property string $valid_from Y-m-d. Sengaja tanpa cast date: cast menyimpan jam juga (Y-m-d H:i:s),
 *                              sehingga cek unik per tanggal dan perbandingan teks tanggal meleset.
 */
class EmissionFactorValue extends Model
{
    /** Periode pertama tiap faktor: berlaku juga untuk semua tanggal sebelumnya */
    public const FIRST_VALID_FROM = '2000-01-01';

    protected $fillable = [
        'emission_factor_id',
        'valid_from',
        'factor_value',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'factor_value' => 'decimal:8',
        ];
    }

    /** @return BelongsTo<EmissionFactor, $this> */
    public function emissionFactor(): BelongsTo
    {
        return $this->belongsTo(EmissionFactor::class);
    }
}
