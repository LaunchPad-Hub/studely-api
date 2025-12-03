<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attempts\SaveProgressRequest;
use App\Http\Resources\AttemptResource;
use App\Models\{Assessment, Attempt, Question, Response, Student, User};
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
            ->orderBy('order', 'asc') // <--- UPDATED: Rely on 'order' column
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

        // Fetch IDs based on the defined order
        ['baseline_id' => $baselineId, 'final_id' => $finalId] = $this->getWorkflowIds($tenantId);

        if (!$baselineId) abort(404, 'No assessments configured.');

        $targetAssessmentId = null;
        $newStatus = null;

        // --- Workflow State Machine ---
        switch ($student->training_status) {
            // 1. Start Baseline (First Attempt)
            case Student::STATUS_READY_BASELINE:
                $targetAssessmentId = $baselineId;
                $newStatus = Student::BASELINE_IN_PROGRESS;
                break;

            // 2. Resume Baseline
            case Student::BASELINE_IN_PROGRESS:
                $targetAssessmentId = $baselineId;
                // Status remains same
                break;

            // 3. Start Final
            case Student::STATUS_READY_FINAL:
                if (!$finalId) abort(404, 'Final assessment not configured yet.');
                $targetAssessmentId = $finalId;
                $newStatus = Student::FINAL_IN_PROGRESS;
                break;

            // 4. Resume Final
            case Student::FINAL_IN_PROGRESS:
                if (!$finalId) abort(404, 'Final assessment not configured yet.');
                $targetAssessmentId = $finalId;
                // Status remains same
                break;

            // Edge Cases
            case Student::STATUS_IN_TRAINING:
                abort(403, 'You are currently in training. Please wait for approval to take the Final Assessment.');

            case Student::STATUS_COMPLETED:
                // Optional: Allow them to view results, but strictly speaking, they can't "start" a new attempt.
                abort(409, 'You have completed the programme.');

            default:
                // Fallback for fresh students (null status) -> Treat as Ready for Baseline
                $targetAssessmentId = $baselineId;
                $newStatus = Student::BASELINE_IN_PROGRESS;
                break;
        }

        // --- Update Status if changing ---
        if ($newStatus && $student->training_status !== $newStatus) {
            $student->training_status = $newStatus;
            $student->save();
        }

        return $this->createAttempt($tenantId, $student->id, $targetAssessmentId);
    }

    /**
     * Shared logic to create or retrieve the active attempt
     */
    private function createAttempt($tenantId, $studentId, $assessmentId)
    {
        // Ensure assessment exists within tenant
        $assessment = Assessment::where('tenant_id', $tenantId)
            ->with(['modules.questions.options'])
            ->findOrFail($assessmentId);

        // Fetch existing attempt or create new one
        $attempt = Attempt::firstOrCreate(
            [
                'tenant_id'     => $tenantId,
                'assessment_id' => $assessmentId,
                'student_id'    => $studentId,
            ],
            [
                'started_at' => now(),
            ]
        );

        $attempt->load(['assessment.modules.questions.options', 'responses']);

        return new AttemptResource($attempt);
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
            $attempt->score = $score;
            $attempt->total_marks = $attempt->assessment->total_marks ?? 1;
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
