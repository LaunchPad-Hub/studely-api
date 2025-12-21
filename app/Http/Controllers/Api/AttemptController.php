<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attempts\SaveProgressRequest;
use App\Http\Resources\AttemptResource;
use App\Models\{Assessment, Attempt, Module, Question, Response, Student, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    /**
     * Helper to get the specific IDs for the workflow based on ORDER.
     * 1st Assessment (lowest order) = Baseline
     * 2nd Assessment (next order)   = Final
     */
    private function getWorkflowIds($tenantId)
    {
        $assessments = Assessment::where('tenant_id', $tenantId)
            ->orderBy('order', 'asc') // Rely on 'order' column
            ->orderBy('id', 'asc')    // Fallback for tie-breaking
            ->limit(2)
            ->pluck('id');

        return [
            'baseline_id' => $assessments[0] ?? null,
            'final_id'    => $assessments[1] ?? null,
        ];
    }

    /**
     * POST /v1/assessments/{id}/attempts
     * (Kept for admin/direct links)
     */
    public function start(Request $r, $assessmentId)
    {
        $user = $r->user();
        if (!$user || !$user->student) abort(403, 'Unauthorized');

        return $this->createAttempt($user->tenant_id, $user->student->id, $assessmentId);
    }

    /**
     * POST /v1/assessment/attempt
     * * INTELLIGENT WORKFLOW START
     * Determines which assessment to start based on Student Training Status + Assessment Order.
     */
     public function startCurrent(Request $r)
    {
        $user = $r->user();
        if (!$user || !$user->student) abort(403, 'Unauthorized');

        $student = $user->student;
        $tenantId = $user->tenant_id;

        ['baseline_id' => $baselineId, 'final_id' => $finalId] = $this->getWorkflowIds($tenantId);

        if (!$baselineId) abort(404, 'No assessments configured.');

        $targetAssessmentId = null;
        $newStatus = null;

        // Modules to filter (null = all modules)
        $weakModuleIds = null;

        switch ($student->training_status) {
            // ... [Baseline Cases remain same] ...
            case Student::STATUS_READY_BASELINE:
                $targetAssessmentId = $baselineId;
                $newStatus = Student::BASELINE_IN_PROGRESS;
                break;

            case Student::BASELINE_IN_PROGRESS:
                $targetAssessmentId = $baselineId;
                break;

            // --- FINAL ASSESSMENT LOGIC ---
            case Student::STATUS_READY_FINAL:
                if (!$finalId) abort(404, 'Final assessment not configured yet.');
                $targetAssessmentId = $finalId;
                $newStatus = Student::FINAL_IN_PROGRESS;

                // ** ADAPTIVE LOGIC: Identify Weak Modules **
                // We check the performance in the Baseline ($baselineId)
                $weakModuleIds = $this->identifyWeakModules($tenantId, $student->id, $baselineId, $finalId);
                break;

            case Student::FINAL_IN_PROGRESS:
                if (!$finalId) abort(404, 'Final assessment not configured yet.');
                $targetAssessmentId = $finalId;
                // Note: We don't recalculate here; we rely on what was saved in Attempt->meta
                break;

            // ... [Edge cases remain same] ...
            case Student::STATUS_IN_TRAINING:
                abort(403, 'You are currently in training.');
            case Student::STATUS_COMPLETED:
                abort(409, 'You have completed the programme.');
            default:
                $targetAssessmentId = $baselineId;
                $newStatus = Student::BASELINE_IN_PROGRESS;
                break;
        }

        if ($newStatus && $student->training_status !== $newStatus) {
            $student->training_status = $newStatus;
            $student->save();
        }

        // Pass the calculated weak modules to createAttempt
        return $this->createAttempt($tenantId, $student->id, $targetAssessmentId, $weakModuleIds);
    }

    /**
     * Updated logic to handle filtering
     */
    private function createAttempt($tenantId, $studentId, $assessmentId, $filterModuleIds = null)
    {
        // 1. Fetch or Create the Attempt
        $attempt = Attempt::firstOrCreate(
            [
                'tenant_id'     => $tenantId,
                'assessment_id' => $assessmentId,
                'student_id'    => $studentId,
            ],
            [
                'started_at' => now(),
                // Store the filter in meta so resuming works correctly later
                'meta' => $filterModuleIds ? ['focused_modules' => $filterModuleIds] : null
            ]
        );

        // 2. Determine which modules to load
        // If we just created it, use $filterModuleIds.
        // If it existed, read from DB meta.
        $savedMeta = $attempt->meta; // Access as array thanks to casts
        $modulesToLoad = $savedMeta['focused_modules'] ?? $filterModuleIds ?? null;

        // 3. Eager Load with Filtering
        $attempt->load([
            'assessment',
            // Advanced constraints on the 'modules' relationship
            'assessment.modules' => function ($query) use ($modulesToLoad) {
                $query->orderBy('order', 'asc');

                // THE MAGIC: If we have specific IDs, only load those
                if ($modulesToLoad && count($modulesToLoad) > 0) {
                    $query->whereIn('id', $modulesToLoad);
                }
            },
            'assessment.modules.questions.options',
            'responses'
        ]);

        return new AttemptResource($attempt);
    }

    /**
     * ADAPTIVE ENGINE
     * Compare Baseline performance to map to Final modules
     */
    private function identifyWeakModules($tenantId, $studentId, $baselineId, $finalId)
    {
        // 1. Find the student's *Submitted* baseline attempt
        $baselineAttempt = Attempt::where('student_id', $studentId)
            ->where('assessment_id', $baselineId)
            ->whereNotNull('submitted_at')
            ->latest()
            ->first();

        if (!$baselineAttempt) return null; // Fallback to all

        // 2. Calculate Score PER MODULE in Baseline
        // We assume Baseline Modules and Final Modules map by "Title" or "Code".
        // If they map by Code, it's safer. Let's assume 'title' for now.

        $modulePerformance = DB::table('responses')
            ->join('questions', 'responses.question_id', '=', 'questions.id')
            ->join('modules', 'questions.module_id', '=', 'modules.id')
            ->join('options', 'responses.option_id', '=', 'options.id') // Assuming MCQ logic mostly
            ->where('responses.attempt_id', $baselineAttempt->id)
            ->select(
                'modules.title', // or modules.code
                DB::raw('SUM(questions.points) as total_possible'),
                DB::raw('SUM(CASE WHEN options.is_correct THEN questions.points ELSE 0 END) as score_obtained')
            )
            ->groupBy('modules.title')
            ->get();

        $weakTitles = [];
        foreach ($modulePerformance as $perf) {
            $pct = ($perf->total_possible > 0)
                ? ($perf->score_obtained / $perf->total_possible) * 100
                : 0;

            // THRESHOLD: If < 70%, they must retake this module
            if ($pct < 70) {
                $weakTitles[] = $perf->title;
            }
        }

        // If they aced everything (empty weakTitles), force a specific logic?
        // Usually, if list is empty, createAttempt returns ALL (null).
        // If you want them to skip the final if they aced baseline, handle that logic upstream.
        if (empty($weakTitles)) return null;

        // 3. Find corresponding IDs in the FINAL Assessment
        $finalModuleIds = Module::where('assessment_id', $finalId)
            ->whereIn('title', $weakTitles) // Matching by Title
            ->pluck('id')
            ->toArray();

        return $finalModuleIds;
    }

    public function saveProgress(SaveProgressRequest $req, $attemptId)
    {
        $tid  = app('tenant.id');
        $data = $req->validated();
        $user = $req->user();

        $attempt = Attempt::where('tenant_id', $tid)->findOrFail($attemptId);

        if ($user->student && $attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot modify this attempt');
        }

        $q = Question::where('tenant_id', $tid)->findOrFail($data['question_id']);

        Response::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $q->id],
            ['option_id' => $data['option_id'] ?? null, 'text_answer' => $data['text_answer'] ?? null]
        );

        return response()->json(['message' => 'saved']);
    }

    public function submit(Request $r, $attemptId)
    {
        $tid  = app('tenant.id');
        $user = User::with('student')->find(Auth::id());

        if (!$user || !$user->student) abort(401, 'Unauthenticated');

        $attempt = Attempt::where('tenant_id', $tid)
            ->with(['responses.option', 'responses.question.options', 'assessment'])
            ->findOrFail($attemptId);

        if ($attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot submit this attempt');
        }

        // Identify which assessment this is in the workflow
        ['baseline_id' => $baselineId, 'final_id' => $finalId] = $this->getWorkflowIds($tid);

        DB::transaction(function () use ($attempt, $user, $baselineId, $finalId) {
            $score = 0;

            // 1. Scoring Logic
            foreach ($attempt->responses as $resp) {
                $q = $resp->question;
                if (!$q) continue;

                $points = $q->points ?? 0;
                $isCorrect = false;

                if ($q->type === 'MCQ' || $q->type === 'BOOLEAN') {
                    if ($resp->option && $resp->option->is_correct) $isCorrect = true;
                } elseif ($q->type === 'TEXT') {
                    if ($resp->text_answer) {
                        $correctOption = $q->options->where('is_correct', true)->first();
                        if ($correctOption && trim(strtolower($resp->text_answer)) === trim(strtolower($correctOption->text))) {
                            $isCorrect = true;
                        }
                    }
                }

                if ($isCorrect) $score += $points;
            }

            // 2. Save Attempt

            // Calculate dynamic total marks based on the sum of points of questions in this assessment
            $totalPossible = DB::table('questions')
                ->join('modules', 'questions.module_id', '=', 'modules.id')
                ->where('modules.assessment_id', $attempt->assessment_id)
                ->sum('points');

            $attempt->score = $score;
            $attempt->total_marks = $totalPossible > 0 ? $totalPossible : 1; // Prevent div by zero
            $attempt->submitted_at = now();
            $attempt->save();

            // 3. WORKFLOW STATE UPDATE
            // Move to next stage based on which assessment was just finished

            if ($attempt->assessment_id == $baselineId) {
                // Finished Baseline -> Move to In Training
                $user->student->update([
                    'training_status' => Student::STATUS_IN_TRAINING
                ]);
            }
            elseif ($attempt->assessment_id == $finalId) {
                // Finished Final -> Move to Completed
                $user->student->update([
                    'training_status' => Student::STATUS_COMPLETED
                ]);
            }
        });

        return new AttemptResource($attempt->load('assessment'));
    }
}
