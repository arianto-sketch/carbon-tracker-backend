<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckProjectMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $projectId = $request->route('projectId') ?? $request->route('id');
        $project = Project::find($projectId);

        if (! $project) {
            return response()->json(['message' => 'Project tidak ditemukan.'], 404);
        }

        $user = $request->user();

        if (! $user->isAdmin() && ! $project->hasUser($user->id)) {
            return response()->json(['message' => 'Akses ditolak. Anda bukan member project ini.'], 403);
        }

        $request->merge(['_project' => $project]);

        return $next($request);
    }
}
