<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{
    Assessment,
    Attempt,
    College,
    Module,
    Question,
    Response,
    Student,
    User
};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{

    /**
     * Admin dashboard endpoint.
     *
     * GET /v1/dashboard/admin?timeframe=today|7d|30d
     *
     * Returns a JSON payload matching AdminDashboardDTO (TS: DashboardData without icons):
     * - tenants: list of colleges for this tenant (for filter dropdown)
     * - kpis: high-level metrics (numbers only; icons handled on frontend)
     * - trend: simple sparkline data (submissions over time)
     * - upcoming: upcoming modules/assessments
     * - recent: recent submissions with score + college
     * - distribution: global score distribution buckets
     * - distributionByTenant: per-college score buckets
     * - progressByCollege: A1/A2 completion per college
     */
    public function admin(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if (!$user->tenant_id) {
            abort(400, 'Tenant context missing for admin.');
        }

        $tenantId = $user->tenant_id;

        $timeframe = $request->query('timeframe', 'today'); // today | 7d | 30d
        [$from, $to] = $this->resolveTimeframe($timeframe);

        // === Colleges (filter list) ===
        $colleges = College::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $tenantsPayload = $colleges->map(fn (College $c) => [
            'id'   => (string) $c->id,
            'name' => $c->name,
        ])->values()->all();

        // === Upcoming assessments (next 7–10 days) ===
        $now = Carbon::now();
        $upcomingWindowEnd = $now->copy()->addDays(10);

        $participantCount = Student::where('tenant_id', $tenantId)->count();

        $upcomingModules = Module::with('assessment')
            ->whereHas('assessment', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->where('is_active', true);
            })
            ->whereBetween('end_at', [$now, $upcomingWindowEnd])
            ->orderBy('end_at')
            ->limit(10)
            ->get();

        $upcoming = $upcomingModules->map(function (Module $m) use ($participantCount) {
            $assessment = $m->assessment;
            return [
                'title'      => $assessment ? $assessment->title : $m->title,
                'course'     => $m->title,
                'due'        => optional($m->end_at)->format('M j'),
                'count'      => $participantCount,
                'status'     => $m->start_at && $m->start_at->isFuture() ? 'Scheduled' : 'Open',
                // NOTE: upcoming items are not college-specific, so leave tenantId/tenantName null.
                'tenantId'   => null,
                'tenantName' => null,
            ];
        })->values()->all();

        // === Attempts (for timeframe) ===
        $attempts = Attempt::where('tenant_id', $tenantId)
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from, $to])
            ->with([
                'student.college',
                'student.user',
                'assessment.modules.questions',
                'responses.option',
                'responses.question',
            ])
            ->orderByDesc('submitted_at')
            ->get();

        $moduleScoreRecords = []; // [ ['score'=>int,'college_id'=>int|null], ... ]
        $studentModuleScores = []; // student_id => [scores...]
        $recent = [];

        foreach ($attempts as $attempt) {
            $assessment = $attempt->assessment;
            if (!$assessment) {
                continue;
            }

            $perAttemptScores = [];
            foreach ($assessment->modules as $module) {
                $score = $this->computeModuleScore($module, $attempt);
                if ($score === null) {
                    continue;
                }

                $college = $attempt->student ? $attempt->student->college : null;
                $collegeId = $college ? $college->id : null;

                $moduleScoreRecords[] = [
                    'score'      => $score,
                    'college_id' => $collegeId,
                ];

                $perAttemptScores[] = $score;
                $studentModuleScores[$attempt->student_id][] = $score;
            }

            if (count($perAttemptScores) === 0) {
                continue;
            }

            $avgScore = (int) round(array_sum($perAttemptScores) / count($perAttemptScores));

            $student = $attempt->student;
            $studentName = $student && $student->user
                ? $student->user->name
                : 'Student #' . $attempt->student_id;

            $college = $student ? $student->college : null;

            $recent[] = [
                'studentId'  => (string) $student->id,
                'student'    => $studentName,
                'module'     => $assessment->title,
                'score'      => $avgScore,
                'when'       => optional($attempt->submitted_at)->diffForHumans(),
                'tenantId'   => $college ? (string) $college->id : null,
                'tenantName' => $college ? $college->name : ($student->institution_name ?? '—'),
            ];
        }

        // Limit recent items
        $recent = array_slice($recent, 0, 20);

        // === Score distributions ===
        $scores = collect($moduleScoreRecords)->pluck('score');
        $distribution = $this->buildScoreDistribution($scores);

        $distributionByTenant = [];
        $byCollege = collect($moduleScoreRecords)->groupBy('college_id');

        foreach ($byCollege as $collegeId => $rows) {
            if ($collegeId === null) {
                continue;
            }
            $distributionByTenant[(string) $collegeId] = $this->buildScoreDistribution(
                $rows->pluck('score')
            );
        }

        // === Trend (very simple: submissions per day over timeframe, up to 12 points) ===
        $trend = $this->buildTrendFromAttempts($attempts, $from, $to);

        // === KPIs ===
        $activeAssessments = Assessment::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $submissionsCount = $attempts->count();
        $avgScoreValue = $scores->count() ? (int) round($scores->avg()) : null;

        $atRiskStudents = 0;
        foreach ($studentModuleScores as $studentId => $list) {
            if (!count($list)) {
                continue;
            }
            $avg = array_sum($list) / count($list);
            if ($avg < 60) {
                $atRiskStudents++;
            }
        }

        $kpis = [
            [
                'label' => 'Active Assessments',
                'value' => $activeAssessments,
                'delta' => '+0', // can refine later
            ],
            [
                'label' => 'Submissions in period',
                'value' => $submissionsCount,
                'delta' => '+0%', // can compare to previous period later
            ],
            [
                'label' => 'Average Score',
                'value' => $avgScoreValue !== null ? ($avgScoreValue . '%') : '—',
                'delta' => '+0%',
            ],
            [
                'label' => 'At-risk Students',
                'value' => $atRiskStudents,
                'delta' => '+0',
            ],
        ];

        // === Progress by College (A1/A2 completion) ===
        $progressByCollege = $this->buildProgressByCollege($tenantId, $colleges);

        $payload = [
            'tenants'             => $tenantsPayload,
            'kpis'                => $kpis,
            'trend'               => $trend,
            'upcoming'            => $upcoming,
            'recent'              => $recent,
            'distribution'        => $distribution,
            'distributionByTenant'=> $distributionByTenant,
            'progressByCollege'   => $progressByCollege,
        ];

        return response()->json(['data' => $payload]);
    }

    /**
     * Resolve timeframe into [from, to] Carbon dates.
     */
    protected function resolveTimeframe(string $timeframe): array
    {
        $now = Carbon::now();

        switch ($timeframe) {
            case '7d':
                $from = $now->copy()->subDays(7);
                break;
            case '30d':
                $from = $now->copy()->subDays(30);
                break;
            case 'today':
            default:
                $from = $now->copy()->startOfDay();
                break;
        }

        return [$from, $now];
    }

    /**
     * Build score distribution buckets (90–100, 80–89, 70–79, 60–69, < 60).
     */
    protected function buildScoreDistribution($scores): array
    {
        $scores = collect($scores)->filter(fn ($v) => $v !== null)->values();
        $total = max(1, $scores->count());

        $buckets = [
            '90–100' => 0,
            '80–89'  => 0,
            '70–79'  => 0,
            '60–69'  => 0,
            '< 60'   => 0,
        ];

        foreach ($scores as $s) {
            if ($s >= 90) {
                $buckets['90–100']++;
            } elseif ($s >= 80) {
                $buckets['80–89']++;
            } elseif ($s >= 70) {
                $buckets['70–79']++;
            } elseif ($s >= 60) {
                $buckets['60–69']++;
            } else {
                $buckets['< 60']++;
            }
        }

        return collect($buckets)->map(function ($count, $label) use ($total) {
            return [
                'label' => $label,
                'pct'   => (int) round(($count / $total) * 100),
            ];
        })->values()->all();
    }

    /**
     * Simple trend = submissions per day over timeframe, max 12 points.
     */
    protected function buildTrendFromAttempts($attempts, Carbon $from, Carbon $to): array
    {
        $grouped = collect($attempts)->groupBy(function (Attempt $a) {
            return optional($a->submitted_at)->format('Y-m-d');
        });

        // Build up to 12 daily buckets from oldest to newest in range
        $days = min(12, $from->diffInDays($to) + 1);
        $trend = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $to->copy()->subDays($i)->format('Y-m-d');
            $trend[] = isset($grouped[$day]) ? $grouped[$day]->count() : 0;
        }

        return $trend;
    }

    /**
     * Build progress per College for A1/A2 (Baseline / Final).
     */
    protected function buildProgressByCollege(int $tenantId, $colleges): array
    {
        $collegeIds = $colleges->pluck('id')->all();

        // Total students per college
        $studentsByCollege = Student::where('tenant_id', $tenantId)
            ->whereIn('college_id', $collegeIds)
            ->selectRaw('college_id, COUNT(*) as total')
            ->groupBy('college_id')
            ->pluck('total', 'college_id');

        // Identify baseline & final assessments for this tenant
        $assessments = Assessment::where('tenant_id', $tenantId)->get();
        $baseline = $assessments->firstWhere('title', 'like', '%Baseline%') ?? $assessments->first();
        $final = $assessments->firstWhere('title', 'like', '%Final%');

        $baselineCounts = collect();
        $finalCounts = collect();

        if ($baseline) {
            $baselineCounts = Attempt::where('assessment_id', $baseline->id)
                ->whereNotNull('submitted_at')
                ->whereHas('student', function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })
                ->join('students', 'attempts.student_id', '=', 'students.id')
                ->selectRaw('students.college_id as college_id, COUNT(DISTINCT attempts.student_id) as c')
                ->groupBy('students.college_id')
                ->pluck('c', 'college_id');
        }

        if ($final) {
            $finalCounts = Attempt::where('assessment_id', $final->id)
                ->whereNotNull('submitted_at')
                ->whereHas('student', function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                })
                ->join('students', 'attempts.student_id', '=', 'students.id')
                ->selectRaw('students.college_id as college_id, COUNT(DISTINCT attempts.student_id) as c')
                ->groupBy('students.college_id')
                ->pluck('c', 'college_id');
        }

        $progress = [];

        foreach ($colleges as $college) {
            $id = $college->id;
            $total = (int) ($studentsByCollege[$id] ?? 0);
            $a1Completed = (int) ($baselineCounts[$id] ?? 0);
            $a2Completed = (int) ($finalCounts[$id] ?? 0);

            $progress[] = [
                'tenantId'   => (string) $id,   // maps to CollegeAssessmentProgress.tenantId on frontend
                'tenantName' => $college->name, // shown as College/University
                'total'      => $total,
                'a1Completed'=> $a1Completed,
                'a2Completed'=> $a2Completed,
                'a1Status'   => $this->progressStatusLabel($a1Completed, $total),
                'a2Status'   => $this->progressStatusLabel($a2Completed, $total),
            ];
        }

        return $progress;
    }

    protected function progressStatusLabel(int $completed, int $total): string
    {
        if ($total === 0 || $completed === 0) {
            return 'Not started';
        }

        if ($completed < $total) {
            return 'In progress';
        }

        return 'Completed';
    }


    /**
     * Student dashboard endpoint.
     *
     * GET /v1/dashboard/student
     *
     * Returns a JSON payload matching StudentDashboardDTO:
     * - stage: current programme stage for this student
     * - nextAction: CTA shown on dashboard (button label + status + href)
     * - activeModule: the module the engine should open next
     * - assessments: baseline + final with per-module status & score
     * - comparisons: baseline vs final per module
     * - aggregateScore: overall percentage across modules
     * - myQueue: submitted & upcoming modules for this student
     */
    public function student(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $tenantId = $user->tenant_id;

        $student = $user->student;
        if (!$student) {
            abort(404, 'Student profile not found.');
        }

        // 1. Load assessments for this tenant.
        // For now we assume there are exactly two: Baseline + Final.
        // You can refine filters (e.g. by programme) later.
        $assessments = Assessment::where('tenant_id', $tenantId)
            ->with([
                'modules' => function ($q) {
                    $q->orderBy('order');
                },
                'modules.questions',
            ])
            ->orderBy('id')
            ->get();

        // Try to identify baseline & final.
        $baseline = $assessments->firstWhere('title', 'like', '%Baseline%')
            ?? $assessments->first();
        $final = $assessments->firstWhere('title', 'like', '%Final%')
            ?? ($assessments->count() > 1 ? $assessments->get(1) : null);

        // 2. Load attempts (per assessment) + responses (+ question + option).
        $attempts = Attempt::where('tenant_id', $tenantId)
            ->where('student_id', $student->id)
            ->with([
                'assessment',
                'responses.option',
                'responses.question',
            ])
            ->get()
            ->keyBy('assessment_id');

        $baselineAttempt = $baseline ? $attempts->get($baseline->id) : null;
        $finalAttempt = $final ? $attempts->get($final->id) : null;

        // 3. Build per-assessment summaries (StudentAssessment[]).
        $assessmentPayloads = [];
        $moduleScoresByAssessment = []; // for comparisons + aggregate

        foreach ($assessments as $assessment) {
            $attempt = $attempts->get($assessment->id);
            $payload = $this->buildStudentAssessment($assessment, $attempt);
            $assessmentPayloads[] = $payload;

            $moduleScoresByAssessment[$assessment->id] = collect($payload['modules'])
                ->keyBy('number');
        }

        // 4. Compute stage + nextAction + activeModule.
        $stage = $this->computeStage($baselineAttempt, $finalAttempt);
        $nextAction = $this->buildNextAction($stage);
        $activeModule = $this->buildActiveModuleSummary($stage, $baseline, $final, $baselineAttempt, $finalAttempt);

        // 5. Build comparisons A1 vs A2 (Baseline vs Final).
        $comparisons = [];
        if ($baseline && $final) {
            $baselineModules = $moduleScoresByAssessment[$baseline->id] ?? collect();
            $finalModules = $moduleScoresByAssessment[$final->id] ?? collect();

            foreach ($baselineModules as $number => $bm) {
                $fm = $finalModules->get($number);
                $comparisons[] = [
                    'module' => $number,
                    'title'  => $bm['title'],
                    'a1'     => $bm['score'],                 // baseline
                    'a2'     => $fm['score'] ?? null,         // final
                ];
            }
        }

        // 6. Aggregate score across all modules (both assessments).
        $allScores = collect($moduleScoresByAssessment)
            ->flatMap(function ($modules) {
                return collect($modules)->pluck('score')->filter(fn ($v) => $v !== null);
            })
            ->values();

        $aggregateScore = $allScores->count()
            ? round($allScores->avg())
            : null;

        // 7. Submitted + upcoming queue for this student.
        $queue = $this->buildMyQueue($baseline, $final, $baselineAttempt, $finalAttempt, $moduleScoresByAssessment, $stage);

        // 8. Final payload.
        $payload = [
            'stage'         => $stage,
            'nextAction'    => $nextAction,
            'activeModule'  => $activeModule,
            'assessments'   => $assessmentPayloads,
            'comparisons'   => $comparisons,
            'aggregateScore'=> $aggregateScore,
            'myQueue'       => $queue,
        ];

        return response()->json(['data' => $payload]);
    }

    /**
     * Build a StudentAssessment payload from an Assessment + optional Attempt.
     *
     * Shape:
     * {
     *   id: number,
     *   title: string,
     *   availability: "open" | "scheduled" | "not_due",
     *   due_at: string|null,
     *   modules: [
     *     { number, title, status: "Complete"|"Incomplete", score, due_at }
     *   ]
     * }
     */
    protected function buildStudentAssessment(Assessment $assessment, ?Attempt $attempt): array
    {
        // For now, treat all active assessments as "open".
        // You can refine this with open_at/close_at later.
        $availability = $assessment->is_active ? 'open' : 'not_due';

        // Use the max module end_at as due date (if available).
        $dueAt = $assessment->modules
            ->filter(fn (Module $m) => $m->end_at !== null)
            ->max('end_at');

        $modules = [];
        foreach ($assessment->modules as $module) {
            $modules[] = $this->buildStudentModule($module, $attempt);
        }

        return [
            'id'           => $assessment->id,
            'title'        => $assessment->title,
            'availability' => $availability,
            'due_at'       => $dueAt ? $dueAt->toISOString() : null,
            'modules'      => $modules,
        ];
    }

    /**
     * Build a StudentModule payload for a given Module & Attempt.
     *
     * Shape:
     * {
     *   number: int,
     *   title: string,
     *   status: "Complete"|"Incomplete",
     *   score: int|null,
     *   due_at: string|null
     * }
     */
    protected function buildStudentModule(Module $module, ?Attempt $attempt): array
    {
        // If no attempt or not submitted yet, we keep score null and status "Incomplete".
        $score = null;
        $status = 'Incomplete';

        if ($attempt && $attempt->submitted_at) {
            $score = $this->computeModuleScore($module, $attempt);
            if ($score !== null) {
                $status = 'Complete';
            }
        }

        return [
            'number' => $module->order ?? 0,
            'title'  => $module->title,
            'status' => $status,
            'score'  => $score,
            'due_at' => $module->end_at ? $module->end_at->toISOString() : null,
        ];
    }

    /**
     * Compute percentage score for a given module inside a given attempt.
     *
     * For now:
     * - MCQ: +1 for each correct response (option.is_correct)
     * - Essay / others: ignored in auto-score (kept for evaluator).
     */
    protected function computeModuleScore(Module $module, Attempt $attempt): ?int
    {
        $questions = $module->questions;
        if ($questions->isEmpty()) {
            return null;
        }

        $questionIds = $questions->pluck('id')->all();

        $responses = $attempt->responses
            ->filter(fn (Response $r) => in_array($r->question_id, $questionIds, true));

        if ($responses->isEmpty()) {
            return null;
        }

        $total = $questions->count();
        $correct = 0;

        foreach ($responses as $resp) {
            $q = $resp->question;
            if (!$q) {
                continue;
            }

            // Only auto-mark MCQ; others will be handled by evaluators.
            if ($q->type === 'MCQ' && $resp->option && $resp->option->is_correct) {
                $correct++;
            }
        }

        if ($total === 0) {
            return null;
        }

        return (int) round(($correct / $total) * 100);
    }

    /**
     * Compute programme stage for this student, based on baseline/final attempts.
     *
     * This is aligned with StudentStage in frontend:
     * - baseline_not_started
     * - baseline_in_progress
     * - final_not_started
     * - final_in_progress
     * - completed
     *
     * (You can later introduce "training" states when you track training.)
     */
    protected function computeStage(?Attempt $baselineAttempt, ?Attempt $finalAttempt): string
    {
        if (!$baselineAttempt) {
            return 'ready_for_baseline';
        }

        if ($baselineAttempt && !$baselineAttempt->submitted_at) {
            return 'baseline_in_progress';
        }

        if ($baselineAttempt && $baselineAttempt->submitted_at && !$finalAttempt) {
            return 'ready_for_final';
        }

        if ($finalAttempt && !$finalAttempt->submitted_at) {
            return 'final_in_progress';
        }

        return 'completed';
    }

    /**
     * Build nextAction object (primary CTA on student dashboard).
     *
     * Shape:
     * {
     *   label: string,
     *   status: "ready"|"locked"|"completed",
     *   helper?: string,
     *   href?: string
     * }
     */
    protected function buildNextAction(string $stage): array
    {
        // The frontend will navigate to /assessment/attempt
        // which boots AssessmentEngine and lets backend decide what to serve.
        $href = '/assessment/attempt';

        switch ($stage) {
            case 'baseline_not_started':
                return [
                    'label'  => 'Start Baseline Assessment',
                    'status' => 'ready',
                    'helper' => 'Modules will unlock one by one.',
                    'href'   => $href,
                ];
            case 'baseline_in_progress':
                return [
                    'label'  => 'Continue Baseline Assessment',
                    'status' => 'ready',
                    'helper' => 'Finish your current module to unlock the next one.',
                    'href'   => $href,
                ];
            case 'final_not_started':
                return [
                    'label'  => 'Start Final Assessment',
                    'status' => 'ready',
                    'helper' => 'You’ll retake selected modules to measure your progress.',
                    'href'   => $href,
                ];
            case 'final_in_progress':
                return [
                    'label'  => 'Continue Final Assessment',
                    'status' => 'ready',
                    'helper' => 'Modules will open in sequence.',
                    'href'   => $href,
                ];
            case 'completed':
                return [
                    'label'  => 'Programme completed',
                    'status' => 'completed',
                    'helper' => 'You can review your scores anytime.',
                ];
            default:
                return [
                    'label'  => 'Assessment not available yet',
                    'status' => 'locked',
                    'helper' => 'Your college will open assessments when they’re ready.',
                ];
        }
    }

    /**
     * Build activeModule summary used by the dashboard "Current module" card.
     *
     * Shape:
     * {
     *   assessmentId,
     *   assessmentTitle,
     *   moduleNumber,
     *   moduleTitle,
     *   totalModules,
     *   status: "not_started"|"in_progress"|"completed",
     *   time_limit_min?: int|null,
     *   time_left_sec?: int|null   // optional, can be null for now
     * }
     */
    protected function buildActiveModuleSummary(
        string $stage,
        ?Assessment $baseline,
        ?Assessment $final,
        ?Attempt $baselineAttempt,
        ?Attempt $finalAttempt
    ): ?array {
        // Decide which assessment is currently relevant.
        $currentAssessment = null;
        $currentAttempt = null;

        if (in_array($stage, ['baseline_not_started', 'baseline_in_progress'], true)) {
            $currentAssessment = $baseline;
            $currentAttempt = $baselineAttempt;
        } elseif (in_array($stage, ['final_not_started', 'final_in_progress'], true)) {
            $currentAssessment = $final;
            $currentAttempt = $finalAttempt;
        }

        if (!$currentAssessment) {
            return null;
        }

        $modules = $currentAssessment->modules->sortBy('order')->values();
        if ($modules->isEmpty()) {
            return null;
        }

        // Find first module that is "next": either first (no attempt),
        // or first one without a computed score.
        $candidateModule = null;

        foreach ($modules as $module) {
            $moduleSummary = $this->buildStudentModule($module, $currentAttempt);
            if ($moduleSummary['score'] === null) {
                $candidateModule = $module;
                break;
            }
        }

        // If all modules have scores, nothing is active.
        if (!$candidateModule) {
            return null;
        }

        $totalModules = $modules->count();
        $number = $candidateModule->order ?? 0;

        return [
            'assessmentId'    => $currentAssessment->id,
            'assessmentTitle' => $currentAssessment->title,
            'moduleNumber'    => $number,
            'moduleTitle'     => $candidateModule->title,
            'totalModules'    => $totalModules,
            'status'          => $currentAttempt && !$currentAttempt->submitted_at ? 'in_progress' : 'not_started',
            'time_limit_min'  => $candidateModule->per_student_time_limit_min,
            // For now we don’t track per-module timers in attempts; return null.
            'time_left_sec'   => null,
        ];
    }

    /**
     * Build myQueue (submitted & upcoming) for the student.
     *
     * Shape:
     * {
     *   submitted: [{ title, when, score }],
     *   upcoming:  [{ title, due_at }]
     * }
     */
    protected function buildMyQueue(
        ?Assessment $baseline,
        ?Assessment $final,
        ?Attempt $baselineAttempt,
        ?Attempt $finalAttempt,
        array $moduleScoresByAssessment,
        string $stage
    ): array {
        $submitted = [];
        $upcoming = [];

        // Helper to push submitted modules for a given assessment + attempt.
        $pushSubmitted = function (?Assessment $assessment, ?Attempt $attempt) use (&$submitted, $moduleScoresByAssessment) {
            if (!$assessment || !$attempt || !$attempt->submitted_at) {
                return;
            }

            $modules = $moduleScoresByAssessment[$assessment->id] ?? collect();

            foreach ($modules as $m) {
                if ($m['score'] === null) {
                    continue;
                }

                $submitted[] = [
                    'title' => $m['title'] . ' (' . $assessment->title . ')',
                    'when'  => optional($attempt->submitted_at)->diffForHumans(),
                    'score' => $m['score'],
                ];
            }
        };

        $pushSubmitted($baseline, $baselineAttempt);
        $pushSubmitted($final, $finalAttempt);

        // Upcoming modules come from the "current" assessment.
        $currentAssessment = null;
        if (in_array($stage, ['baseline_not_started', 'baseline_in_progress'], true)) {
            $currentAssessment = $baseline;
        } elseif (in_array($stage, ['final_not_started', 'final_in_progress'], true)) {
            $currentAssessment = $final;
        }

        if ($currentAssessment) {
            foreach ($currentAssessment->modules as $module) {
                $moduleSummary = $this->buildStudentModule(
                    $module,
                    $currentAssessment->id === ($baseline->id ?? null) ? $baselineAttempt : $finalAttempt
                );

                if ($moduleSummary['score'] === null) {
                    $upcoming[] = [
                        'title'  => $moduleSummary['title'],
                        'due_at' => $moduleSummary['due_at'],
                    ];
                }
            }
        }

        return [
            'submitted' => $submitted,
            'upcoming'  => $upcoming,
        ];
    }
}
