<?php

namespace App\Services;

use App\Models\EmissionFactor;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class EmissionFactorService
{
    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = EmissionFactor::with('category');

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        } else {
            $query->where('is_active', true);
        }

        return $query->orderBy('category_id')->orderBy('name')->paginate($perPage);
    }

    public function create(array $data, User $creator): EmissionFactor
    {
        return EmissionFactor::create([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'source_unit' => $data['source_unit'],
            'factor_value' => $data['factor_value'],
            'factor_unit' => $data['factor_unit'] ?? 'kg CO2e',
            'source' => $data['source'] ?? null,
            'version' => $data['version'] ?? null,
            'is_active' => true,
            'created_by' => $creator->id,
        ]);
    }

    public function update(EmissionFactor $factor, array $data, User $updater): EmissionFactor
    {
        $factor->update([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'source_unit' => $data['source_unit'],
            'factor_value' => $data['factor_value'],
            'factor_unit' => $data['factor_unit'] ?? $factor->factor_unit,
            'source' => $data['source'] ?? null,
            'version' => $data['version'] ?? null,
            'is_active' => $data['is_active'] ?? $factor->is_active,
            'updated_by' => $updater->id,
        ]);

        return $factor->fresh('category');
    }

    public function deactivate(EmissionFactor $factor, User $updater): EmissionFactor
    {
        $factor->update(['is_active' => false, 'updated_by' => $updater->id]);

        return $factor;
    }
}
