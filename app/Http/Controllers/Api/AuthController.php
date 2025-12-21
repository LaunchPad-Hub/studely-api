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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp, private RegistrationCache $regCache) {}

    /** POST /api/v1/register/init */
    public function registerInit(RegisterInitRequest $request)
    {
        $data = $request->validated();

        // Safety checks: no duplicate verified account by email/phone/reg_no
        if (User::where('email', $data['email'])->whereNotNull('registered_at')->exists()) {
            return response()->json(['message'=>'An account with this email already exists.'], 422);
        }
        if (User::where('phone', $data['mobile'])->whereNotNull('registered_at')->exists()) {
            return response()->json(['message'=>'An account with this mobile already exists.'], 422);
        }
        if (Student::where('reg_no', $data['reg_no'])->exists()) {
            // optional: extra strictness if needed
        }

        // Store pending payload (hash password here; never keep plain text)
        $payload = [
            'user' => [
                'name'          => $data['full_name'],
                'email'         => $data['email'],
                'phone'         => $data['mobile'],
                'password_hash' => Hash::make($data['password']),
            ],
            'student' => [
                // The "university" is now a FK to colleges.id
                'college_id'        => $data['university_id'],
                'institution_name'  => $data['institution_name'],
                'gender'            => $data['gender'],
                'dob'               => $data['dob'],
                'admission_year'    => $data['admission_year'],
                'current_semester'  => $data['current_semester'],
                'reg_no'            => $data['reg_no'],
            ],
        ];

        $this->regCache->put(
            $data['email'],
            $payload,
            (int) config('auth.otp_ttl', 10)
        );

        // Send OTP to email
        $this->otp->request(
            channel: 'email',
            identifier: $data['email'],
            purpose: 'register',
            ttlMinutes: (int) config('auth.otp_ttl', 10),
            digits: (int) config('auth.otp_digits', 6)
        );

        return response()->json(['message' => 'otp_sent']);
    }


    /** POST /api/v1/register/complete */
    public function registerComplete(RegisterCompleteRequest $request)
    {
        $email = $request->validated()['email'];
        $otp   = $request->validated()['otp'];

        $verified = $this->otp->verify(
            channel: 'email',
            identifier: $email,
            purpose: 'register',
            code: $otp,
            maxAttempts: (int)config('auth.otp_max_attempts', 5)
        );

        if (! $verified) {
            return response()->json(['message' => 'invalid_or_expired_code'], 401);
        }

        $payload = $this->regCache->get($email);
        if (!$payload) {
            return response()->json(['message' => 'registration_session_expired'], 410);
        }

        DB::beginTransaction();
        try {
            // 1) Create the user
            $user = User::create([
                'tenant_id'         => 1,
                'name'              => $payload['user']['name'],
                'email'             => $payload['user']['email'],
                'phone'             => $payload['user']['phone'],
                'password'          => $payload['user']['password_hash'],
                'email_verified_at' => now(),
                'registered_at'     => now(),
            ]);

            // 2) Create the student profile
            $user->student()->create([
                'tenant_id'        => 1,
                'college_id'       => $payload['student']['college_id'],   // <— 🔥 new
                'reg_no'           => $payload['student']['reg_no'],
                'institution_name' => $payload['student']['institution_name'],
                // keep university_name nullable / for legacy if you want:
                'university_name'  => null,
                'gender'           => $payload['student']['gender'],
                'dob'              => $payload['student']['dob'],
                'admission_year'   => $payload['student']['admission_year'],
                'current_semester' => $payload['student']['current_semester'],
            ]);

            // 3) Optional role
            if (method_exists($user, 'assignRole')) {
                $user->assignRole('Student');
            }

            DB::commit();
            $this->regCache->forget($email);

            // Auto-login on success
            $token = $user->createToken('web', ['*'])->plainTextToken;

            return response()->json([
                'message'    => 'Congratulations! You have been onboarded to "The Launchpad".',
                'token'      => $token,
                'token_type' => 'Bearer',
                'user'       => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'email'     => $user->email,
                    'tenant_id' => $user->tenant_id,
                    'role'      => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->first() : null,
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('Register complete failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'registration_failed'], 500);
        }
    }



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

        $firstRole = $user->getRoleNames()->first();

        // if (!in_array($firstRole, ['SuperAdmin', 'Evaluator']) && (blank($user->registered_at) || blank($user->email_verified_at))) {
        //     return response()->json([
        //         'message' => 'Please complete registration before logging in.',
        //     ], 403);
        // }

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
