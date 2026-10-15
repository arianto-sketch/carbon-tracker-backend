<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->email,
            $request->password,
            $request->device_name ?? 'api'
        );

        return response()->json([
            'data' => [
                'user' => new ProfileResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
            'message' => 'Login berhasil.',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Dicari dan dikirim SETELAH respons: waktu respons sama untuk email terdaftar maupun tidak,
        // jadi keberadaan akun tidak bisa ditebak dari lamanya respons
        $email = (string) $request->validated('email');
        dispatch(fn () => app(AuthService::class)->requestPasswordReset($email))->afterResponse();

        return response()->json(['message' => 'Jika email terdaftar dan aktif, link reset password sudah dikirim.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());

        return response()->json(['message' => 'Password berhasil direset. Silakan login dengan password baru.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logout berhasil.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new ProfileResource($request->user()),
        ]);
    }

    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->authService->updateProfile($request->user(), $request->validated());

        return response()->json([
            'data' => new ProfileResource($user),
            'message' => 'Profil berhasil diperbarui.',
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            $request->user(),
            $request->current_password,
            $request->password
        );

        return response()->json(['message' => 'Password berhasil diubah.']);
    }
}
