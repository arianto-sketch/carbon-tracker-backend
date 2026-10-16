<?php

namespace App\Http\Requests\ReductionInitiative;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ReductionInitiative;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReductionInitiativeRequest extends FormRequest
{
    /**
     * Dicek sebelum validasi: orang luar tidak boleh bisa menebak siapa anggota project
     * lewat pesan validasi PIC.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = Project::findOrFail($this->route('projectId'));

        return $user->isAdmin() || in_array($project->effectiveRole($user), ['owner', 'member'], true);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner atau member project yang bisa mengelola rencana aksi.');
    }

    public function rules(): array
    {
        return [
            'title'                  => ['required', 'string', 'max:200'],
            'description'            => ['nullable', 'string', 'max:2000'],
            'category_id'            => ['nullable', 'integer', 'exists:emission_categories,id'],
            // Estimasi penghematan per tahun
            'estimated_reduction_kg' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'status'                 => ['required', Rule::in(ReductionInitiative::STATUSES)],
            'pic_user_id'            => ['bail', 'nullable', 'integer', $this->picIsActiveMember(...)],
            'target_date'            => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status tidak valid. Pilih: planned, in_progress, done, atau cancelled.',
        ];
    }

    private function picIsActiveMember(string $attribute, mixed $value, Closure $fail): void
    {
        $isActiveMember = ProjectMember::where('project_id', $this->route('projectId'))
            ->where('user_id', $value)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->exists();

        if (! $isActiveMember && ! $this->keepsCurrentPic((int) $value)) {
            $fail('PIC harus anggota aktif project ini.');
        }
    }

    /** Saat edit, PIC lama yang sudah keluar/dinonaktifkan boleh dipertahankan */
    protected function keepsCurrentPic(int $userId): bool
    {
        return false;
    }
}
