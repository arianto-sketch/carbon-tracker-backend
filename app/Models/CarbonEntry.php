<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarbonEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'emission_factor_id',
        'category_id',
        'scope',
        'entry_date',
        'period_month',
        'period_year',
        'quantity',
        'source_unit',
        'emission_factor_value',
        'co2e_kg',
        'description',
        'vendor_name',
        'activity_type',
        'attachment_path',
        'attachment_name',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'quantity' => 'decimal:4',
            'emission_factor_value' => 'decimal:8',
            'co2e_kg' => 'decimal:4',
            'scope' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<EmissionFactor, $this> */
    public function emissionFactor(): BelongsTo
    {
        return $this->belongsTo(EmissionFactor::class, 'emission_factor_id');
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

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Draft atau ditolak: masih boleh diubah, dihapus, dan di-submit (ulang). */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Alasan user tidak boleh me-review entri ini (aturan 4 mata), atau null bila boleh.
     * updated_by = pengubah isi terakhir (submit/approve/reject tidak mengubahnya).
     */
    public function reviewBlockedReason(User $user): ?string
    {
        if ((int) $this->created_by === (int) $user->id) {
            return 'Tidak bisa me-review entri buatan sendiri.';
        }

        if ($this->updated_by !== null && (int) $this->updated_by === (int) $user->id) {
            return 'Tidak bisa me-review entri yang isinya terakhir Anda ubah.';
        }

        return null;
    }

    /**
     * Nama file saat diunduh: nama dari pengunggah, tapi ekstensinya diambil dari path tersimpan
     * (hasil deteksi isi file). PDF yang diberi nama "struk.bat" terunduh sebagai "struk.pdf".
     * Hanya huruf, angka, spasi dan . _ ( ) - yang dipertahankan: karakter seperti " ; * = ' bisa
     * mengecoh parser Content-Disposition di klien, karakter kontrol membuat header gagal dibuat,
     * dan karakter bidi (RTLO) membalik tampilan ekstensi.
     */
    public function attachmentDownloadName(): string
    {
        $base = (string) preg_replace('/[^\p{L}\p{M}\p{N} ._()-]+/u', '_', pathinfo((string) $this->attachment_name, PATHINFO_FILENAME));
        $base = mb_substr(trim($base, ' .'), 0, 150) ?: 'lampiran';
        $extension = pathinfo((string) $this->attachment_path, PATHINFO_EXTENSION);

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }
}
