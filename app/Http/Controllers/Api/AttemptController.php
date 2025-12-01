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
     * OLD: explicit start by assessment id
     * POST /v1/assessments/{id}/attempts
     *
     * (kept for admin / direct links)
     */
    public function start(Request $r, $assessmentId)
    {
        $user = $r->user(); // same as Auth::user(), cleaner
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $tid     = $user->tenant_id;
        $student = $user->student;

        if (!$student) {
            abort(403, 'Only students can start attempts');
        }

        // Load assessment with modules + questions + options for the engine
        $assessment = Assessment::where('tenant_id', $tid)
            ->with([
                'modules' => function ($q) {
                    $q->orderBy('order');
                },
                'modules.questions.options',
            ])
            ->findOrFail($assessmentId);

        // Single attempt per (tenant, assessment, student)
        $attempt = Attempt::firstOrCreate(
            [
                'tenant_id'     => $tid,
                'assessment_id' => $assessment->id,
                'student_id'    => $student->id,
            ],
            [
                'started_at' => now(),
            ]
        );

        $attempt->load([
            'assessment.modules.questions.options',
            'responses',
        ]);

        return new AttemptResource($attempt);
    }

    /**
     * NEW: start "the right" assessment for this student.
     *
     * POST /v1/assessment/attempt
     *
     * Uses Baseline/Final + programme stage:
     * - if baseline not started → baseline
     * - if baseline in progress → baseline
     * - if baseline done & final not started → final
     * - if final in progress → final
     * - if everything done → 409 "Programme already completed"
     */
    public function startCurrent(Request $r)
    {
        $user = $r->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $tid     = $user->tenant_id;
        $student = $user->student;

        if (!$student) {
            abort(403, 'Only students can start attempts');
        }

        // Load all assessments for this tenant
        $assessments = Assessment::where('tenant_id', $tid)
            ->with([
                'modules' => function ($q) {
                    $q->orderBy('order');
                },
                'modules.questions.options',
            ])
            ->orderBy('id')
            ->get();

        if ($assessments->isEmpty()) {
            abort(404, 'No assessments configured for this tenant');
        }

        // Identify Baseline & Final (by title, fallback to order)
        $baseline = $assessments->firstWhere('title', 'like', '%Baseline%')
            ?? $assessments->first();
        $final = $assessments->firstWhere('title', 'like', '%Final%')
            ?? ($assessments->count() > 1 ? $assessments->get(1) : null);

        // Load existing attempts for this student
        $attempts = Attempt::where('tenant_id', $tid)
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('assessment_id');

        $baselineAttempt = $baseline ? $attempts->get($baseline->id) : null;
        $finalAttempt    = $final ? $attempts->get($final->id)    : null;

        // Compute stage (same as dashboard)
        $stage = $this->computeStage($baselineAttempt, $finalAttempt);

        // Decide which assessment is "current"
        $assessment = null;
        switch ($stage) {
            case 'baseline_not_started':
            case 'baseline_in_progress':
                $assessment = $baseline;
                break;
            case 'final_not_started':
            case 'final_in_progress':
                $assessment = $final;
                break;
            case 'completed':
                abort(409, 'Programme already completed.');
            default:
                $assessment = $baseline ?? $assessments->first();
        }

        if (!$assessment) {
            abort(404, 'No assessment available for your stage.');
        }

        // Single attempt per assessment & student
        $attempt = Attempt::firstOrCreate(
            [
                'tenant_id'     => $tid,
                'assessment_id' => $assessment->id,
                'student_id'    => $student->id,
            ],
            [
                'started_at' => now(),
            ]
        );

        $attempt->load([
            'assessment.modules.questions.options',
            'responses',
        ]);

        return new AttemptResource($attempt);
    }

    /**
     * Shared stage logic (same idea as in DashboardController).
     */
    protected function computeStage(?Attempt $baselineAttempt, ?Attempt $finalAttempt): string
    {
        if (!$baselineAttempt) {
            return 'baseline_not_started';
        }

        if ($baselineAttempt && !$baselineAttempt->submitted_at) {
            return 'baseline_in_progress';
        }

        if ($baselineAttempt && $baselineAttempt->submitted_at && !$finalAttempt) {
            return 'final_not_started';
        }

        if ($finalAttempt && !$finalAttempt->submitted_at) {
            return 'final_in_progress';
        }

        return 'completed';
    }

    public function saveProgress(SaveProgressRequest $req, $attemptId)
    {
        $tid  = app('tenant.id');
        $data = $req->validated();

        $user = $req->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $attempt = Attempt::where('tenant_id', $tid)->findOrFail($attemptId);

        if ($user->student && $attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot modify this attempt');
        }

        $q = Question::where('tenant_id', $tid)
            ->with('module')
            ->findOrFail($data['question_id']);

        if (!$q->module || $q->module->assessment_id !== $attempt->assessment_id) {
            abort(403, 'Question does not belong to this assessment');
        }

        Response::updateOrCreate(
            [
                'attempt_id'  => $attempt->id,
                'question_id' => $q->id,
            ],
            [
                'option_id'   => $data['option_id'] ?? null,
                'text_answer' => $data['text_answer'] ?? null,
            ]
        );

        return response()->json(['message' => 'saved']);
    }

    public function submit(Request $r, $attemptId)
    {
        $tid  = app('tenant.id');
        $user = User::with('student')->find(Auth::id());

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $attempt = Attempt::where('tenant_id', $tid)
            ->with([
                'responses.option',
                'responses.question.options',
                'assessment', // Ensure this is loaded to access total_mark & passing_percentage
            ])
            ->findOrFail($attemptId);

        if ($user->student && $attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot submit this attempt');
        }

        DB::transaction(function () use ($attempt) {
            $score = 0;

            // --- 1. Calculate Score ---
            foreach ($attempt->responses as $resp) {
                $q = $resp->question;
                if (!$q) continue;

                $points = $q->points ?? 0;
                $isCorrect = false;

                switch ($q->type) {
                    case 'MCQ':
                    case 'BOOLEAN':
                        if ($resp->option && $resp->option->is_correct) {
                            $isCorrect = true;
                        }
                        break;
                    case 'TEXT':
                        if ($resp->text_answer) {
                            $correctOption = $q->options->where('is_correct', true)->first();
                            if ($correctOption) {
                                $userAns = trim(strtolower($resp->text_answer));
                                $correctAns = trim(strtolower($correctOption->text));
                                if ($userAns === $correctAns) $isCorrect = true;
                            }
                        }
                        break;
                }

                if ($isCorrect) {
                    $score += $points;
                }
            }

            // --- 3. Save ---
            $attempt->score        = $score;
            // $attempt->status       = $status; // Ensure you have this column in DB
            $attempt->total_marks = $attempt->assessment->total_marks ?? 1; // avoid div by zero
            $attempt->submitted_at = now();
            $attempt->save();


        });

        // --- 4. Do on training ---
        $user->student->training_status = Student::STATUS_IN_TRAINING;
        $user->student->save();

        $attempt->load('assessment');

        return new AttemptResource($attempt);
    }
}
