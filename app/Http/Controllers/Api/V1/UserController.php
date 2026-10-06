<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UserService $service) {}

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
