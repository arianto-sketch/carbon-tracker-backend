<?php

use App\Models\AuditLog;
use App\Models\ProjectMember;
use Illuminate\Database\Migrations\Migration;

/**
 * Data lama: user ber-role global Viewer yang masih owner/member di project diturunkan menjadi viewer
 * project (tercatat di audit log). Satu-satunya owner sebuah project dibiarkan (aturan minimal satu
 * owner); wewenangnya tetap dicabut lewat otorisasi, dan admin menunjuk owner baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProjectMember::query()
            ->whereIn('role', ['owner', 'member'])
            ->whereHas('user', fn ($q) => $q->where('role', 'viewer'))
            ->get()
            ->each(function (ProjectMember $membership) {
                $hasOtherOwner = ProjectMember::where('project_id', $membership->project_id)
                    ->where('role', 'owner')
                    ->whereKeyNot($membership->getKey())
                    ->whereHas('user', fn ($q) => $q->where('role', '!=', 'viewer'))
                    ->exists();

                if ($membership->role === 'owner' && ! $hasOtherOwner) {
                    return;
                }

                $oldRole = $membership->role;
                $membership->update(['role' => 'viewer']);
                AuditLog::record('updated', $membership, ['role' => $oldRole], ['role' => 'viewer']);
            });
    }

    /** Perbaikan data satu arah: role lama tetap tercatat di audit log bila perlu dikembalikan manual */
    public function down(): void {}
};
