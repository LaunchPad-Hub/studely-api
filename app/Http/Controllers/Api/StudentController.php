<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\StoreStudentRequest;
use App\Http\Requests\Students\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Mail\StudentInvite;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $tid = Auth::user()->tenant_id;

        // This allows your frontend 'list({ per_page: 1000 })' to work.
        $perPage = $request->input('per_page', 100);

        // Eager load relationships for better performance
        $query = Student::with(['user', 'university', 'college'])
            ->where('tenant_id', $tid)
            ->latest();

        // Filter by University/College/Cohort
        if ($request->has('university')) {
            $query->where('university_id', $request->university);
        }

        return StudentResource::collection($query->paginate($perPage));
    }

    public function store(StoreStudentRequest $req)
    {
        $tid = Auth::user()->tenant_id;
        $data = $req->validated();

        return DB::transaction(function () use ($data, $tid) {
            // 1. Create the User Account first
            // Note: StoreStudentRequest should validate that 'email' is unique in users table
            $userData = $data['user'] ?? $data; // Handle if fields are flattened or nested

            $user = User::create([
                'name'      => $userData['name'],
                'email'     => $userData['email'],
                'phone'     => $userData['phone'] ?? null,
                'tenant_id' => $tid,
                // 'password'  => Str::password(12), // Secure random password
                'password'  => Hash::make('TempPass123!'), // Temporary password; user will reset via invite
            ]);

            // 2. Assign Role (Optional but recommended)
            $user->assignRole('Student');

            // 3. Auto-generate Unique Reg No
            $regNo = $this->generateUniqueRegNo($tid);

            // 4. Create Student Profile
            $student = Student::create([
                'tenant_id'     => $tid,
                'user_id'       => $user->id,
                'reg_no'        => $regNo,
                'university_id' => $data['university_id'] ?? null,
                'college_id'    => $data['college_id'] ?? null,
                'branch'        => $data['branch'] ?? null,
                'cohort'        => $data['cohort'] ?? null,
                'meta'          => $data['meta'] ?? null,
                // 'status'        => Student::STATUS_CREATED, // Ensure this constant exists in Model
            ]);


            // 5. Auto-invite on create
            // $this->sendInviteEmail($student);

            // 5. Load relationships for the resource response
            return new StudentResource($student->load(['user', 'university', 'college']));
        });
    }

    public function show($id)
    {
        $tid = Auth::user()->tenant_id;
        $student = Student::with(['user', 'university', 'college'])
            ->where('tenant_id', $tid)
            ->findOrFail($id);

        return new StudentResource($student);
    }

    public function update(UpdateStudentRequest $req, $id)
    {
        $tid = Auth::user()->tenant_id;
        $student = Student::where('tenant_id', $tid)->findOrFail($id);
        $data = $req->validated();

        return DB::transaction(function () use ($student, $data) {
            // 1. Update Linked User (Name, Email, Phone)
            // Check if flattened fields exist or are inside a 'user' array
            $name = $data['name'] ?? $data['user']['name'] ?? null;
            $email = $data['email'] ?? $data['user']['email'] ?? null;
            $phone = $data['phone'] ?? $data['user']['phone'] ?? null;

            if ($name || $email || $phone) {
                $student->user->update(array_filter([
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                ], fn($v) => !is_null($v))); // Only update provided fields
            }

            // 2. Update Student Fields
            $student->update([
                'university_id' => $data['university_id'] ?? $student->university_id,
                'college_id'    => $data['college_id'] ?? $student->college_id,
                'branch'        => $data['branch'] ?? $student->branch,
                'cohort'        => $data['cohort'] ?? $student->cohort,
                'meta'          => $data['meta'] ?? $student->meta,
                // 'reg_no' is usually immutable, so we don't update it here
            ]);

            return new StudentResource($student->load(['user', 'university', 'college']));
        });
    }

    public function destroy($id)
    {
        $tid = Auth::user()->tenant_id;
        $student = Student::where('tenant_id', $tid)->findOrFail($id);

        // Optional: Delete the user account too?
        // $student->user->delete();

        $student->delete();
        return response()->json(['message' => 'Student deleted successfully']);
    }

    public function invite($id)
    {
        $tid = Auth::user()->tenant_id;
        $student = Student::where('tenant_id', $tid)->with('user')->findOrFail($id);
        $user = $student->user;

        if (!$user) {
            return response()->json(['message' => 'User record not found.'], 404);
        }

        $this->sendInviteEmail($student);

        return response()->json([
            'code' => 200,
            'message' => 'Invite sent successfully to ' . $user->email
        ]);
    }

    /**
     * Helper to handle the email logic
     */
    private function sendInviteEmail(Student $student)
    {
        $user = $student->user;
        $token = Password::createToken($user);

        // Ensure FRONTEND_URL is set in .env
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $url = "{$frontendUrl}/auth/set-password?token={$token}&email=" . urlencode($user->email);

        Mail::to($user)->send(new StudentInvite($user, $url));

        $student->update(['status' => Student::STATUS_INVITED]);
    }

    /**
     * Generate a unique Registration Number
     * Format: ST-{YEAR}-{RANDOM_HEX} (e.g., ST-2025-A1B2C3)
     */
    private function generateUniqueRegNo($tenantId)
    {
        $year = date('Y');
        $prefix = "ST-{$year}-";

        do {
            // Generate 6 random uppercase alphanumeric characters
            $random = strtoupper(Str::random(6));
            $regNo = $prefix . $random;

            // Check uniqueness within the tenant
            $exists = Student::where('tenant_id', $tenantId)
                ->where('reg_no', $regNo)
                ->exists();

        } while ($exists);

        return $regNo;
    }
}
