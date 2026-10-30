<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AdminResource;
use App\Models\Admin;
use App\Models\LoginAttempt;
use App\Support\ApiResponse;
use App\Support\Auth\AdminIpPolicy;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Auth\IssuedTokens;
use App\Support\OneTimePassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outils communs aux écrans de connexion du Back Office : recherche du compte, refus, journal des tentatives et
 * réponse de connexion.
 */
abstract class AdminAuthController extends Controller
{
    public function __construct(public OneTimePassword $otp, public AdminSessions $sessions, public AdminIpPolicy $ipPolicy) {}

    protected function findAdmin(string $email): ?Admin
    {
        return Admin::query()->where('email', $email)->first();
    }

    /**
     * Session ouverte : jetons et administrateur connecté.
     */
    protected function signedIn(IssuedTokens $tokens, Admin $admin): JsonResponse
    {
        return ApiResponse::success([...$tokens->toArray(), 'admin' => AdminResource::make($admin->load('roles'))]);
    }

    /**
     * Réponse après l’envoi du code de connexion à l’adresse du compte (`$resendIn` : attente restante avant un renvoi).
     */
    protected function codeSent(string $email, string $message, ?int $resendIn = null): JsonResponse
    {
        return ApiResponse::success([
            'mfa_required' => true,
            'channel' => 'email',
            'destination' => AuthController::maskEmail($email),
            'expires_in' => $this->otp->lifetime() * 60,
            'resend_in' => $resendIn ?? $this->otp->resendAfter(),
            'code_length' => OneTimePassword::length(),
        ], $message, 202);
    }

    protected function refused(string $message, string $code, int $status = 403): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }

    protected function locked(Admin $admin): JsonResponse
    {
        return response()->json([
            'message' => 'Trop de tentatives. Réessayez à '.$admin->locked_until->timezone('Africa/Abidjan')->format('H:i').'.',
            'code' => 'account_locked',
            'locked_until' => $admin->locked_until->toIso8601String(),
        ], 423);
    }

    protected function device(Request $request): DeviceContext
    {
        return new DeviceContext(
            deviceName: (string) ($request->input('device_name') ?: 'Navigateur'),
            deviceId: $request->input('device_id'),
            platform: 'web',
            appVersion: null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    protected function recordAttempt(Request $request, string $email, bool $success, ?Admin $admin = null, ?string $failureReason = null): void
    {
        LoginAttempt::create([
            'authenticatable_type' => 'admin',
            'authenticatable_id' => $admin?->getKey(),
            'identifier' => $email,
            'success' => $success,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }
}
