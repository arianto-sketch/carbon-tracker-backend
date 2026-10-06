<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Aturan akses entri emisi dalam sebuah project (dipakai controller entri & import).
 */
trait AuthorizesProjectEntries
{
    /** Membaca: admin atau anggota project dengan role apa pun. */
    protected function authorizeProjectAccess(Request $request, Project $project): void
    {
        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            abort(403, 'Akses ditolak. Anda bukan member project ini.');
        }
    }

    /** Menulis entri: admin, atau owner/member project yang role globalnya bukan viewer. */
    protected function authorizeProjectWrite(Request $request, Project $project): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        $projectRole = $project->getUserRole($user->id);

        if ($user->role === 'viewer' || ! in_array($projectRole, ['owner', 'member'], true)) {
            abort(403, 'Akses ditolak. Role Anda hanya bisa melihat entri.');
        }
    }
}
