<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\StoreAssessmentRequest;
use App\Http\Requests\Assessments\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Mail\AssessmentInvite;
use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AssessmentController extends Controller
{
    public function index(Request $r)
    {
        $tid = app('tenant.id');

        $q = Assessment::where('tenant_id', $tid)
            ->withCount('modules')
            ->latest();

        // (optional future filters here)

        return AssessmentResource::collection($q->paginate(20));
    }

    public function store(StoreAssessmentRequest $req)
    {
        $tid = app('tenant.id');
        $data = $req->validated();

        // Create assessment only; modules will be created separately
        $a = Assessment::create($data + ['tenant_id' => $tid]);

        // ensure modules_count is available in resource if you use it
        $a->loadCount('modules');

        return new AssessmentResource($a);
    }

    public function show($id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)
            ->with([
                // modules + questions + options for the engine
                'modules.questions.options',
                // direct “through” questions collection for engine convenience
                'questions.options',
                // rubric
                'rubric.criteria',
            ])
            ->findOrFail($id);

        return new AssessmentResource($a);
    }

    public function update(UpdateAssessmentRequest $req, $id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)->findOrFail($id);
        $a->update($req->validated());
        $a->loadCount('modules');

        return new AssessmentResource($a);
    }

    public function destroy($id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)->findOrFail($id);
        $a->delete();

        return response()->json(['message' => 'deleted']);
    }

    /**
     * Get list of students with their status for this specific assessment.
     * Optimized for scalability using Left Joins.
     */
    public function candidates(Request $request, $id)
    {
        $tid = app('tenant.id');
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');

        // We select Students, but LEFT JOIN attempts to see if they took THIS assessment
        $query = Student::query()
            ->select([
                'students.id',
                'students.reg_no',
                'students.user_id',
                'students.training_status',
                // specific attempt columns
                'attempts.id as attempt_id',
                'attempts.submitted_at',
                'attempts.started_at',
                'attempts.score',
                'attempts.total_marks'
            ])
            ->with('user:id,name,email') // Eager load user for names
            ->leftJoin('attempts', function ($join) use ($id) {
                $join->on('students.id', '=', 'attempts.student_id')
                     ->where('attempts.assessment_id', '=', $id);
            })
            ->where('students.tenant_id', $tid);

        // Search Logic
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('students.reg_no', 'like', "%{$search}%")
                  ->orWhereHas('user', function($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by Status (Optional, useful for UI tabs)
        if ($status = $request->input('status')) {
            if ($status === 'completed') {
                $query->whereNotNull('attempts.submitted_at');
            } elseif ($status === 'pending') {
                $query->whereNull('attempts.id');
            }
        }

        $paginated = $query->paginate($perPage);

        // Transform to add a clear 'status' string
        $paginated->getCollection()->transform(function ($s) {
            $status = 'not_started';
            if ($s->attempt_id) {
                $status = $s->submitted_at ? 'submitted' : 'in_progress';
            }

            return [
                'id' => $s->id,
                'name' => $s->user->name ?? 'Unknown',
                'email' => $s->user->email ?? '',
                'reg_no' => $s->reg_no,
                'training_status' => $s->training_status,
                'status' => $status,
                'score' => $s->score,
                'total_marks' => $s->total_marks,
                'submitted_at' => $s->submitted_at,
            ];
        });

        return response()->json($paginated);
    }

    /**
     * Bulk assign/push assessment to students.
     * Updates student status based on assessment type.
     */
    public function assign(Request $request, $id)
    {
        $tid = app('tenant.id');
        $data = $request->validate([
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:students,id'
        ]);

        $assessment = Assessment::where('tenant_id', $tid)->findOrFail($id);

        $students = Student::whereIn('id', $data['student_ids'])
            ->where('tenant_id', $tid)
            ->with('user')
            ->get();

        // 1. Determine Target Status based on Assessment Title keywords
        $targetStatus = null;
        if (stripos($assessment->title, 'Baseline') !== false) {
            $targetStatus = Student::STATUS_READY_BASELINE;
        } elseif (stripos($assessment->title, 'Final') !== false) {
            $targetStatus = Student::STATUS_READY_FINAL;
        }

        $count = 0;
        foreach ($students as $student) {
            // 2. Update Student Status if a lifecycle stage is matched
            if ($targetStatus) {
                // Prevent regressing a 'completed' student back to 'ready' if needed,
                // but usually manual push overrides.
                $student->update(['training_status' => $targetStatus]);
            }

            // 3. Send Email
            if ($student->user && $student->user->email) {
                Mail::to($student->user)->queue(new AssessmentInvite($assessment, $student));
                $count++;
            }
        }

        return response()->json([
            'message' => "Assessment pushed to {$count} students.",
            'new_status' => $targetStatus
        ]);
    }
}
