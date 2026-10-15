<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserService $service) {}

    /** Admin dan PM (calon owner project) mencari user untuk dijadikan anggota project */
    public function lookup(Request $request): JsonResponse
    {
        if ($request->user()->isViewer()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $search = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']])['search'];

        return response()->json(['data' => $this->service->lookup($search)]);
    }

    public function history(int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->history(User::findOrFail($id))]);
    }

    public function sendPasswordResetLink(Request $request, AuthService $auth, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $auth->sendPasswordResetLink($user, $request->user());

        return response()->json(['message' => "Link reset password dikirim ke {$user->email}."]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role'   => ['nullable', 'in:admin,pm,viewer'],
        ]);

        $users = $this->service->list($filters);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $user = $this->service->create($request->validated());

        return response()->json([
            'data'    => new UserResource($user),
            'message' => 'User berhasil dibuat.',
        ], 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user = $this->service->update($user, $request->validated(), $request->user());

        return response()->json([
            'data'    => new UserResource($user),
            'message' => 'User berhasil diperbarui.',
        ]);
    }
}
