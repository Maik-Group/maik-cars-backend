<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AccountAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountAuthenticationController extends Controller
{
    public function __construct(private readonly AccountAuthenticationService $accounts) {}

    public function register(Request $request): JsonResponse
    {
        $email = $this->email($request);
        $this->accounts->requestRegistration($email);

        return $this->codeRequestAccepted();
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $email = $this->email($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);
        $this->accounts->register($email, $data['code'], $data['name'], $data['password']);

        return response()->json(['message' => 'Your email is verified. You can now sign in.'], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->accounts->requestPasswordReset($this->email($request));

        return $this->codeRequestAccepted();
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = $this->email($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);
        $this->accounts->resetPassword($email, $data['code'], $data['password']);

        return response()->json(['message' => 'Password reset successfully. Please sign in.']);
    }

    private function email(Request $request): string
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        return Str::lower(trim($data['email']));
    }

    private function codeRequestAccepted(): JsonResponse
    {
        return response()->json(['message' => 'If your request is eligible, a code will be sent to your email.']);
    }
}
