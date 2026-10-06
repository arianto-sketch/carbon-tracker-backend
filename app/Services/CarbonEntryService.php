<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CarbonEntryService
{
    /** Field teknis yang tidak ditampilkan di riwayat perubahan. */
    private const HISTORY_HIDDEN_FIELDS = ['updated_at', 'updated_by', 'created_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at'];

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
                    foreach (Arr::except($log->new_values ?? [], self::HISTORY_HIDDEN_FIELDS) as $field => $new) {
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
        if (! $entry->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya entry berstatus draft atau ditolak yang bisa diubah.'],
            ]);
        }

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
    }

    public function delete(CarbonEntry $entry): void
    {
        if (! $entry->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya entry berstatus draft atau ditolak yang bisa dihapus.'],
            ]);
        }

        $entry->delete();
    }

    public function submit(CarbonEntry $entry): CarbonEntry
    {
        if (! $entry->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya entry berstatus draft atau ditolak yang bisa di-submit.'],
            ]);
        }

        // Submit ulang setelah ditolak: bersihkan data penolakan sebelumnya
        $entry->update([
            'status'           => 'submitted',
            'rejection_reason' => null,
            'rejected_by'      => null,
            'rejected_at'      => null,
        ]);

        return $entry->fresh();
    }

    public function approve(CarbonEntry $entry, User $approver): CarbonEntry
    {
        if ($entry->status !== 'submitted') {
            throw ValidationException::withMessages([
                'status' => ['Hanya entry berstatus submitted yang bisa di-approve.'],
            ]);
        }

        $entry->update([
            'status'      => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $entry->fresh(['approvedBy']);
    }

    public function reject(CarbonEntry $entry, User $reviewer, string $reason): CarbonEntry
    {
        if ($entry->status !== 'submitted') {
            throw ValidationException::withMessages([
                'status' => ['Hanya entry berstatus submitted yang bisa ditolak.'],
            ]);
        }

        $entry->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'rejected_by'      => $reviewer->id,
            'rejected_at'      => now(),
        ]);

        return $entry->fresh(['rejectedBy']);
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
