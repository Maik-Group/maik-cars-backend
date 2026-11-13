<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is currently inactive. Please contact support.',
            ], 403);
        }

        if ($user->requiresMobileEmailVerification()) {
            return response()->json([
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => 'Verify your email using Forgot password to continue.',
            ], 403);
        }

        $deviceId = trim((string) ($validated['device_id'] ?? ''));
        $fcmToken = trim((string) ($validated['fcm_token'] ?? ''));

        if ($user->unlimited_devices) {
            // Unlimited-device accounts bypass device authorization entirely.
            // FCM remains notification metadata only and may rotate on every login.
            if ($fcmToken !== '' && $user->fcm_token !== $fcmToken) {
                $user->forceFill(['fcm_token' => $fcmToken])->save();
            }
        } else {
            if ($deviceId === '' && $fcmToken === '') {
                return $this->missingDeviceIdentifierResponse();
            }

            if ($deviceId !== '') {
                if ($user->hasAuthorizedDeviceId()) {
                    if (! $user->isAuthorizedDeviceId($deviceId)) {
                        return $this->deviceNotAuthorizedResponse();
                    }
                } elseif ($user->hasAuthorizedDevice()) {
                    // Seamless migration from the temporary FCM binding: the first app version
                    // that sends a Device ID must also prove it is the currently authorized installation.
                    if ($fcmToken === '' || ! $user->isAuthorizedDeviceToken($fcmToken)) {
                        return $this->deviceNotAuthorizedResponse();
                    }

                    $user->upgradeAuthorizedDeviceId($deviceId);
                } else {
                    $user->bindAuthorizedDevice($deviceId, $fcmToken !== '' ? $fcmToken : null);
                }

                // Once Device ID is authoritative, FCM may rotate without affecting device authorization.
                if ($fcmToken !== '' && $user->fcm_token !== $fcmToken) {
                    $user->forceFill(['fcm_token' => $fcmToken])->save();
                }
            } else {
                // Temporary compatibility path for the current app version.
                if (! $user->hasAuthorizedDevice()) {
                    $user->bindAuthorizedDevice(null, $fcmToken);
                } elseif (! $user->isAuthorizedDeviceToken($fcmToken)) {
                    return $this->deviceNotAuthorizedResponse();
                }
            }

            // Standard accounts keep only one live mobile session.
            $user->tokens()->delete();
        }

        $token = $user->createToken('mobile-api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'preferred_language' => $user->preferredLanguageOrDefault(),
                'discount_margin_percent' => $user->discountMarginPercent(),
            ],
        ]);
    }

    private function missingDeviceIdentifierResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'A device identifier is required for this account.',
            'errors' => [
                'deviceId' => ['Device ID or FCM token is required.'],
                'fcmToken' => ['Device ID or FCM token is required.'],
            ],
        ], 422);
    }

    private function deviceNotAuthorizedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This account is already linked to another device. Please contact support to reset the authorized device.',
            'code' => 'DEVICE_NOT_AUTHORIZED',
        ], 403);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user?->unlimited_devices) {
            $user->currentAccessToken()?->delete();
        } elseif ($user) {
            // Signing out from a standard account releases its single-device binding
            // so the next successful login can claim the account from another device.
            $user->resetAuthorizedDevice();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
