<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\EmissionFactorValue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nilai faktor emisi per periode. Setiap perubahan periode:
 * - menyelaraskan emission_factors.factor_value dengan nilai yang berlaku hari ini;
 * - menghitung ulang entri draft & ditolak yang nilainya berubah (tercatat di riwayat entri);
 * - entri submitted/approved TIDAK diubah; jumlah yang memakai nilai berbeda dilaporkan sebagai "locked".
 */
class EmissionFactorValueService
{
    /**
     * Periode terbaru dulu, dengan tanggal berakhir (sehari sebelum periode berikutnya; null = masih berlaku).
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(EmissionFactor $factor): array
    {
        $values = $factor->values()->get()->values();

        return $values->map(fn (EmissionFactorValue $value, int $i) => [
            'id'           => $value->id,
            'valid_from'   => $value->valid_from,
            'valid_until'  => isset($values[$i + 1]) ? Carbon::parse($values[$i + 1]->valid_from)->subDay()->toDateString() : null,
            'factor_value' => (float) $value->factor_value,
            'source'       => $value->source,
        ])->reverse()->values()->all();
    }

    /** @return array{value: EmissionFactorValue, recalculated: int, locked: int} */
    public function add(EmissionFactor $factor, array $data, User $admin): array
    {
        return DB::transaction(function () use ($factor, $data, $admin) {
            $this->lock($factor);
            $value = $factor->values()->create([
                'valid_from'   => $data['valid_from'],
                'factor_value' => $data['factor_value'],
                'source'       => $data['source'] ?? null,
                'created_by'   => $admin->id,
            ]);

            return ['value' => $value, ...$this->afterChange($factor)];
        });
    }

    /** @return array{recalculated: int, locked: int} */
    public function update(EmissionFactor $factor, EmissionFactorValue $value, array $data): array
    {
        return DB::transaction(function () use ($factor, $value, $data) {
            $this->lock($factor);
            $value->update([
                'factor_value' => $data['factor_value'],
                'source'       => array_key_exists('source', $data) ? $data['source'] : $value->source,
            ]);

            return $this->afterChange($factor);
        });
    }

    /** @return array{recalculated: int, locked: int} */
    public function delete(EmissionFactor $factor, EmissionFactorValue $value): array
    {
        return DB::transaction(function () use ($factor, $value) {
            $this->lock($factor);

            if ($factor->values()->count() <= 1) {
                throw ValidationException::withMessages([
                    'value' => ['Faktor emisi harus punya minimal satu nilai. Ubah nilainya, bukan menghapus.'],
                ]);
            }

            $value->delete();

            return $this->afterChange($factor);
        });
    }

    /**
     * Koreksi nilai periode yang berlaku hari ini (form faktor tanpa periode, klien lama).
     *
     * @return array{recalculated: int, locked: int}
     */
    public function correctCurrent(EmissionFactor $factor, string|float $factorValue): array
    {
        return DB::transaction(function () use ($factor, $factorValue) {
            $this->lock($factor);
            $factor->periodOn($this->today())?->update(['factor_value' => $factorValue]);

            return $this->afterChange($factor);
        });
    }

    /** Entri submitted/approved yang memakai nilai berbeda dari periode yang berlaku pada tanggalnya */
    public function lockedCount(EmissionFactor $factor): int
    {
        $factor->load('values');
        $locked = 0;

        CarbonEntry::where('emission_factor_id', $factor->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->select(['id', 'entry_date', 'emission_factor_value'])
            ->chunkById(500, function ($entries) use ($factor, &$locked) {
                foreach ($entries as $entry) {
                    if ((string) $entry->emission_factor_value !== $factor->valueOn($entry->entry_date)) {
                        $locked++;
                    }
                }
            });

        return $locked;
    }

    /** @return array{recalculated: int, locked: int} */
    private function afterChange(EmissionFactor $factor): array
    {
        $factor->load('values');
        $factor->update(['factor_value' => $factor->valueOn($this->today())]);

        $recalculated = 0;
        CarbonEntry::where('emission_factor_id', $factor->id)
            ->whereIn('status', ['draft', 'rejected'])
            ->chunkById(200, function ($entries) use ($factor, &$recalculated) {
                foreach ($entries as $entry) {
                    $value = $factor->valueOn($entry->entry_date);

                    if ((string) $entry->emission_factor_value === $value) {
                        continue;
                    }

                    // updated_by tidak diubah: perhitungan ulang bukan suntingan isi entri (aturan 4 mata tetap)
                    $entry->update([
                        'emission_factor_value' => $value,
                        'co2e_kg'               => round((float) $entry->quantity * (float) $value, 4),
                    ]);
                    $recalculated++;
                }
            });

        return ['recalculated' => $recalculated, 'locked' => $this->lockedCount($factor)];
    }

    /** Perubahan periode satu faktor diproses bergiliran */
    private function lock(EmissionFactor $factor): void
    {
        EmissionFactor::whereKey($factor->id)->lockForUpdate()->firstOrFail();
    }

    private function today(): string
    {
        return now(config('app.business_timezone'))->toDateString();
    }
}
