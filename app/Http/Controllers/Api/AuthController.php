<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OtpRequestRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Requests\Auth\RegisterCompleteRequest;
use App\Http\Requests\Auth\RegisterInitRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Auth\RegistrationCache;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RulesPassword;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp, private RegistrationCache $regCache) {}



    /** POST /api/v1/login */
    public function login(Request $request)
    {
        $data = $request->validate([
            'login'   => ['sometimes','string','max:255'],
            'email'   => ['sometimes','string','max:255'],
            'password'=> ['required','string'],
            'device'  => ['nullable','string','max:60'],
        ]);

        $identifier = $data['login'] ?? $data['email'] ?? null;
        if (!$identifier) {
            return response()->json(['message' => 'The login field is required.'], 422);
        }


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

    /** POST /api/v1/set-password */
    public function setPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', RulesPassword::defaults()],
        ]);

        // 1. Attempt to reset the password
        // This validates the token, email, and expiration automatically.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        // 2. Handle result
        if ($status === Password::PASSWORD_RESET) {

            // Optional: Mark student as active
            $user = User::where('email', $request->email)->first();
            if ($user && $user->student) {
                // Ensure you have a STATUS_ACTIVE constant or similar
                $user->student->update([
                    'status' => 'active',
                    'training_status' => 'ready_for_baseline'
                ]);
            }

            return response()->json(['message' => __($status)]);
        }

        // Token invalid, expired, or email doesn't match
        return response()->json(['email' => __($status)], 400);
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
