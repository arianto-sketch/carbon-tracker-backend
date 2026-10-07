<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Notifications\EntryWorkflowNotification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class CarbonEntryService
{
    /** Field yang boleh tampil di riwayat perubahan (whitelist: field teknis & path file internal tidak ikut). */
    private const HISTORY_FIELDS = [
        'status', 'quantity', 'entry_date', 'co2e_kg', 'emission_factor_id', 'description',
        'vendor_name', 'activity_type', 'rejection_reason', 'attachment_name',
    ];

    private const ATTACHMENT_DISK = 'local';

    /** Perubahan status yang ditampilkan sebagai event tersendiri. */
    private const STATUS_EVENTS = ['submitted', 'approved', 'rejected'];

    public function list(Project $project, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = CarbonEntry::with(['emissionFactor', 'category', 'createdBy', 'approvedBy', 'rejectedBy'])
            ->where('project_id', $project->id);

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['period_year'])) {
            $query->where('period_year', $filters['period_year']);
        }

        if (isset($filters['period_month'])) {
            $query->where('period_month', $filters['period_month']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('entry_date')->paginate($perPage);
    }

    /**
     * Riwayat perubahan entri dari audit_logs (ditulis CarbonEntryObserver), urut kronologis.
     */
    public function history(CarbonEntry $entry): array
    {
        return AuditLog::with('user')
            ->where('model_type', CarbonEntry::class)
            ->where('model_id', $entry->id)
            ->orderBy('id')
            ->get()
            ->map(function (AuditLog $log) {
                $changes = [];

                if ($log->action === 'updated') {
                    foreach (Arr::only($log->new_values ?? [], self::HISTORY_FIELDS) as $field => $new) {
                        $changes[$field] = ['old' => $log->old_values[$field] ?? null, 'new' => $new];
                    }
                }

                $newStatus = $changes['status']['new'] ?? null;

                return [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'event'      => in_array($newStatus, self::STATUS_EVENTS, true) ? $newStatus : $log->action,
                    'user'       => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                    'changes'    => (object) $changes,
                    'created_at' => $log->created_at?->toISOString(),
                ];
            })
            ->all();
    }

    /**
     * Simpan/ganti lampiran bukti. File lama baru dihapus setelah data entri tersimpan.
     */
    public function attach(CarbonEntry $entry, UploadedFile $file, User $user): CarbonEntry
    {
        $message = 'Lampiran hanya bisa diubah pada entry berstatus draft atau ditolak.';
        $this->ensureEditable($entry, $message);

        // File disimpan sebelum transaksi supaya lock baris tidak tertahan selama upload.
        // Kalau status berubah di tengah jalan atau update gagal, file baru dihapus lagi.
        $path = $file->storeAs(
            "attachments/{$entry->project_id}/{$entry->id}",
            Str::uuid().'.'.$file->extension(),
            self::ATTACHMENT_DISK,
        );

        try {
            [$entry, $oldPath] = DB::transaction(function () use ($entry, $file, $user, $path, $message) {
                $entry = $this->lockForChange($entry);
                $this->ensureEditable($entry, $message);

                $oldPath = $entry->attachment_path;
                $entry->update([
                    'attachment_path' => $path,
                    'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'updated_by'      => $user->id,
                ]);

                return [$entry, $oldPath];
            });
        } catch (Throwable $e) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($path);

            throw $e;
        }

        if ($oldPath) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($oldPath);
        }

        return $entry->fresh();
    }

    public function detach(CarbonEntry $entry, User $user): CarbonEntry
    {
        // File baru dihapus setelah commit, supaya entri tidak menunjuk file yang sudah hilang
        [$entry, $oldPath] = DB::transaction(function () use ($entry, $user) {
            $entry = $this->lockForChange($entry);
            $this->ensureEditable($entry, 'Lampiran hanya bisa dihapus pada entry berstatus draft atau ditolak.');

            $oldPath = $entry->attachment_path;
            $entry->update(['attachment_path' => null, 'attachment_name' => null, 'updated_by' => $user->id]);

            return [$entry, $oldPath];
        });

        if ($oldPath) {
            Storage::disk(self::ATTACHMENT_DISK)->delete($oldPath);
        }

        return $entry->fresh();
    }

    public function attachmentDisk(): string
    {
        return self::ATTACHMENT_DISK;
    }

    private function ensureEditable(CarbonEntry $entry, string $message): void
    {
        if (! $entry->isEditable()) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    /**
     * Baca ulang entri dengan baris terkunci (wajib dipanggil di dalam transaksi). Status dicek
     * dari data terbaru, bukan dari model yang dibawa request: approve dan tolak yang datang
     * bersamaan diproses bergiliran, dan yang kedua mendapat 422 alih-alih menimpa yang pertama.
     */
    private function lockForChange(CarbonEntry $entry): CarbonEntry
    {
        return CarbonEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
    }

    public function create(array $data, Project $project, User $creator): CarbonEntry
    {
        $factor = EmissionFactor::where('id', $data['emission_factor_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $co2eKg = $this->calculate($data['quantity'], $factor->factor_value);
        $date = Carbon::parse($data['entry_date']);

        return CarbonEntry::create([
            'project_id'            => $project->id,
            'emission_factor_id'    => $factor->id,
            'category_id'           => $factor->category_id,
            'entry_date'            => $date->toDateString(),
            'period_month'          => $date->month,
            'period_year'           => $date->year,
            'quantity'              => $data['quantity'],
            'source_unit'           => $factor->source_unit,
            'emission_factor_value' => $factor->factor_value,
            'co2e_kg'               => $co2eKg,
            'description'           => $data['description'] ?? null,
            'vendor_name'           => $data['vendor_name'] ?? null,
            'activity_type'         => $data['activity_type'] ?? null,
            'status'                => 'draft',
            'created_by'            => $creator->id,
        ]);
    }

    public function update(CarbonEntry $entry, array $data, User $updater): CarbonEntry
    {
        return DB::transaction(function () use ($entry, $data, $updater) {
            $entry = $this->lockForChange($entry);
            $this->ensureEditable($entry, 'Hanya entry berstatus draft atau ditolak yang bisa diubah.');

            $factor = EmissionFactor::where('id', $data['emission_factor_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $co2eKg = $this->calculate($data['quantity'], $factor->factor_value);
            $date = Carbon::parse($data['entry_date']);

            $entry->update([
                'emission_factor_id'    => $factor->id,
                'category_id'           => $factor->category_id,
                'entry_date'            => $date->toDateString(),
                'period_month'          => $date->month,
                'period_year'           => $date->year,
                'quantity'              => $data['quantity'],
                'source_unit'           => $factor->source_unit,
                'emission_factor_value' => $factor->factor_value,
                'co2e_kg'               => $co2eKg,
                'description'           => $data['description'] ?? null,
                'vendor_name'           => $data['vendor_name'] ?? null,
                'activity_type'         => $data['activity_type'] ?? null,
                'updated_by'            => $updater->id,
            ]);

            return $entry->fresh(['emissionFactor', 'category', 'createdBy']);
        });
    }

    public function delete(CarbonEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = $this->lockForChange($entry);
            $this->ensureEditable($entry, 'Hanya entry berstatus draft atau ditolak yang bisa dihapus.');

            $entry->delete();
        });
    }

    public function submit(CarbonEntry $entry, ?User $submitter = null): CarbonEntry
    {
        $entry = DB::transaction(function () use ($entry) {
            $entry = $this->lockForChange($entry);
            $this->ensureEditable($entry, 'Hanya entry berstatus draft atau ditolak yang bisa di-submit.');

            // Submit ulang setelah ditolak: bersihkan data penolakan sebelumnya
            $entry->update([
                'status'           => 'submitted',
                'rejection_reason' => null,
                'rejected_by'      => null,
                'rejected_at'      => null,
            ]);

            return $entry;
        });

        if ($submitter) {
            $this->notifyOwners($entry, $submitter);
        }

        return $entry->fresh();
    }

    public function approve(CarbonEntry $entry, User $approver): CarbonEntry
    {
        $entry = DB::transaction(function () use ($entry, $approver) {
            $entry = $this->lockForChange($entry);
            $this->ensureSubmitted($entry, 'Hanya entry berstatus submitted yang bisa di-approve.');
            $this->ensureReviewableBy($entry, $approver);

            $entry->update([
                'status'      => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $entry;
        });

        $this->notifyCreator($entry, new EntryWorkflowNotification('entry_approved', $entry, $approver));

        return $entry->fresh(['approvedBy']);
    }

    public function reject(CarbonEntry $entry, User $reviewer, string $reason): CarbonEntry
    {
        $entry = DB::transaction(function () use ($entry, $reviewer, $reason) {
            $entry = $this->lockForChange($entry);
            $this->ensureSubmitted($entry, 'Hanya entry berstatus submitted yang bisa ditolak.');
            $this->ensureReviewableBy($entry, $reviewer);

            $entry->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by'      => $reviewer->id,
                'rejected_at'      => now(),
            ]);

            return $entry;
        });

        $this->notifyCreator($entry, new EntryWorkflowNotification('entry_rejected', $entry, $reviewer, $reason));

        return $entry->fresh(['rejectedBy']);
    }

    private function ensureSubmitted(CarbonEntry $entry, string $message): void
    {
        if ($entry->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    /**
     * Aturan 4 mata dicek ulang pada baris terkunci: controller membaca entri sebelum lock, jadi
     * reviewer yang mengubah isi entri lalu submit di request paralel bisa lolos pengecekan awal.
     */
    private function ensureReviewableBy(CarbonEntry $entry, User $reviewer): void
    {
        if ($reason = $entry->reviewBlockedReason($reviewer)) {
            throw new AuthorizationException($reason);
        }
    }

    /** Owner project diberi tahu ada entri menunggu approval (kecuali pengirimnya sendiri). */
    private function notifyOwners(CarbonEntry $entry, User $submitter): void
    {
        $ownerIds = ProjectMember::where('project_id', $entry->project_id)->where('role', 'owner')->pluck('user_id');
        $owners = User::whereIn('id', $ownerIds)->whereKeyNot($submitter->id)->where('is_active', true)->get();

        $this->sendSafely($owners, new EntryWorkflowNotification('entry_submitted', $entry, $submitter));
    }

    private function notifyCreator(CarbonEntry $entry, EntryWorkflowNotification $notification): void
    {
        if ($entry->createdBy) {
            $this->sendSafely([$entry->createdBy], $notification);
        }
    }

    /**
     * Notifikasi bersifat best-effort: kegagalan dicatat di log tanpa membatalkan
     * perubahan status yang sudah tersimpan.
     */
    private function sendSafely(iterable $users, EntryWorkflowNotification $notification): void
    {
        try {
            Notification::send($users, $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function bulkCreate(array $items, Project $project, User $creator): array
    {
        $results = ['created' => [], 'errors' => []];

        foreach ($items as $index => $item) {
            try {
                $entry = $this->create($item, $project, $creator);
                $results['created'][] = $entry->id;
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function calculate(float $quantity, float $factorValue): float
    {
        return round($quantity * $factorValue, 4);
    }
}
