<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class EmissionFactor extends Model
{
    /** Scope GHG Protocol: 1 = emisi langsung, 2 = energi yang dibeli, 3 = tidak langsung lainnya */
    public const SCOPES = [1, 2, 3];

    protected $fillable = [
        'category_id',
        'scope',
        'name',
        'slug',
        'description',
        'source_unit',
        'factor_value',
        'factor_unit',
        'source',
        'version',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // Setiap faktor baru (API, seeder, import data) langsung punya periode pertama
        static::created(function (EmissionFactor $factor) {
            $factor->values()->create([
                'valid_from'   => EmissionFactorValue::FIRST_VALID_FROM,
                'factor_value' => $factor->factor_value,
                'source'       => $factor->source,
                'created_by'   => $factor->created_by,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'factor_value' => 'decimal:8',
            'scope' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmissionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CarbonEntry, $this> */
    public function carbonEntries(): HasMany
    {
        return $this->hasMany(CarbonEntry::class, 'emission_factor_id');
    }

    /**
     * Nilai per periode, urut dari yang paling lama berlaku
     *
     * @return HasMany<EmissionFactorValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(EmissionFactorValue::class)->orderBy('valid_from');
    }

    /**
     * Periode yang berlaku pada tanggal tertentu: valid_from terakhir yang <= tanggal itu. Tanggal
     * sebelum periode pertama memakai periode pertama. Memakai relasi `values` bila sudah dimuat.
     */
    public function periodOn(CarbonInterface|string $date): ?EmissionFactorValue
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $values = $this->relationLoaded('values') ? $this->values : $this->values()->get();

        return $values->filter(fn (EmissionFactorValue $v) => $v->valid_from <= $day)->last()
            ?? $values->first();
    }

    /** Nilai faktor (string desimal) yang berlaku pada tanggal tertentu */
    public function valueOn(CarbonInterface|string $date): string
    {
        return (string) ($this->periodOn($date)->factor_value ?? $this->factor_value);
    }
}
