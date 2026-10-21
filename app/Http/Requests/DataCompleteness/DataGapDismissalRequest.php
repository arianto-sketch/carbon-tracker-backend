<?php

namespace App\Http\Requests\DataCompleteness;

use App\Models\Project;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/** Menandai / membatalkan "tidak ada data bulan ini" (body untuk POST, query string untuk DELETE) */
class DataGapDismissalRequest extends FormRequest
{
    /** Owner/member (bukan viewer) atau admin; dicek sebelum validasi */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = Project::findOrFail($this->route('projectId'));

        return $user->isAdmin() || in_array($project->effectiveRole($user), ['owner', 'member'], true);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner atau member project yang bisa menandai data bulanan.');
    }

    public function rules(): array
    {
        return [
            'period_year'        => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month'       => ['bail', 'required', 'integer', 'min:1', 'max:12', $this->notInFuture(...)],
            // Kosong = seluruh project (tidak ada entri sama sekali bulan itu)
            'emission_factor_id' => ['nullable', 'integer', 'exists:emission_factors,id'],
        ];
    }

    private function notInFuture(string $attribute, mixed $value, Closure $fail): void
    {
        $now = now(config('app.business_timezone'));
        if ((int) $this->input('period_year') * 100 + (int) $value > $now->year * 100 + $now->month) {
            $fail('Bulan itu belum terjadi.');
        }
    }
}
