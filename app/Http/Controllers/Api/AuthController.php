<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** POST /api/v1/login */
    public function login(Request $request)
    {
        $data = $request->validate([
            // use one field for either email or reg_no (fallback to 'email' for backward compat)
            'login'   => ['sometimes','string','max:255'],
            'email'   => ['sometimes','string','max:255'], // deprecated: will be used only if 'login' absent
            'password'=> ['required','string'],
            'device'  => ['nullable','string','max:60'],
            // optional tenant scoping if you need it (uncomment if applicable)
            // 'tenant_id' => ['sometimes','integer','exists:tenants,id'],
            // 'tenant_code' => ['sometimes','string','exists:tenants,code'],
        ]);

        $identifier = $data['login'] ?? $data['email'] ?? null;
        if (!$identifier) {
            return response()->json(['message' => 'The login field is required.'], 422);
        }

        // Optionally resolve tenant for scoping (uncomment if you want strict per-tenant auth)
        // $tenant = null;
        // if (!empty($data['tenant_id'])) {
        //     $tenant = \App\Models\Tenant::find($data['tenant_id']);
        // } elseif (!empty($data['tenant_code'])) {
        //     $tenant = \App\Models\Tenant::where('code', $data['tenant_code'])->first();
        // }

        // Find user by email OR by student's reg_no
        $userQuery = User::query()
            ->when(str_contains($identifier, '@'), function ($q) use ($identifier) {
                // Looks like an email → try direct email match first
                $q->where('email', $identifier);
            }, function ($q) use ($identifier) {
                // Not an email → treat as reg_no on related Student
                $q->whereHas('student', function ($s) use ($identifier) {
                    $s->where('reg_no', $identifier);
                });
            });

        // If you need tenant scoping, apply it (uncomment if you enabled the tenant block above)
        // if ($tenant) {
        //     $userQuery->where('tenant_id', $tenant->id);
        // }

        $user = $userQuery->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // revoke old tokens for this device name if you want single-session per device
        $deviceName = $data['device'] ?? 'web';
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, ['*'])->plainTextToken;

        return response()->json([
            'message'    => 'ok',
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'tenant_id' => $user->tenant_id,
                'roles'     => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
            ],
        ]);
    }


    /** POST /api/v1/logout */
    public function logout(Request $request)
    {
        // revoke current token
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /** POST /api/v1/otp/request  (channel, identifier, purpose) */
    public function otpRequest(OtpRequestRequest $request)
    {
        $this->otp->request(
            $request->channel,
            $request->identifier,
            $request->purpose,
            ttlMinutes: (int) (config('auth.otp_ttl', 10)),
            digits: (int) (config('auth.otp_digits', 6))
        );

        return response()->json(['message'=>'otp_sent']);
    }

    /** POST /api/v1/otp/verify  (channel, identifier, purpose, code) */
    public function otpVerify(OtpVerifyRequest $request)
    {
        $ok = $this->otp->verify(
            $request->channel,
            $request->identifier,
            $request->purpose,
            $request->code,
            maxAttempts: (int) (config('auth.otp_max_attempts', 5))
        );

        return $ok
            ? response()->json(['message'=>'verified'])
            : response()->json(['message'=>'invalid_or_expired_code'], 401);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['user' => null], 200);
        }

        // Spatie: returns a Collection of strings
        $firstRole = $user->getRoleNames()->first(); // e.g. "admin" or null

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $firstRole,
                'tenant_id' => $user->tenant_id,
                'tenant_name' => $user->tenant->name ?? null

                // optional: keep the full list too
                // 'roles' => $user->getRoleNames(),
            ],
        ]);
    }

}
