<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attempts\SaveProgressRequest;
use App\Http\Resources\AttemptResource;
use App\Models\{Assessment, Attempt, Question, Response, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    public function start(Request $r, $assessmentId)
    {
        $user = $r->user(); // same as Auth::user(), cleaner
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $tid = $user->tenant_id;
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

        // Load relations for resource
        $attempt->load([
            'assessment.modules.questions.options',
            'responses', // so you can later prefill answers if needed
        ]);

        return new AttemptResource($attempt);
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

        // Ensure this attempt belongs to the current student's tenant/user
        if ($user->student && $attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot modify this attempt');
        }

        // Question belongs to a module → assessment
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
        $tid   = app('tenant.id');
        $user  = $r->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        $attempt = Attempt::where('tenant_id', $tid)
            ->with([
                'responses.option',
                'responses.question',
                'assessment',
            ])
            ->findOrFail($attemptId);

        if ($user->student && $attempt->student_id !== $user->student->id) {
            abort(403, 'You cannot submit this attempt');
        }

        DB::transaction(function () use ($attempt) {
            $score = 0;

            foreach ($attempt->responses as $resp) {
                // MCQ scoring: +1 per correct option
                if (
                    $resp->question &&
                    $resp->question->type === 'MCQ' &&
                    $resp->option &&
                    $resp->option->is_correct
                ) {
                    $score += 1;
                }
            }

            $attempt->score        = $score;
            $attempt->submitted_at = now();
            $attempt->save();
        });

        $attempt->load('assessment');

        return new AttemptResource($attempt);
    }
}
