<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Search Endpoint for StudentSelector
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $tenantId = $request->user()->tenant_id;

        $q = Student::where('tenant_id', $tenantId)
            ->with('user:id,name,email')
            ->select('id', 'user_id', 'reg_no');

        if ($query) {
            $q->where(function($sub) use ($query) {
                $sub->where('reg_no', 'like', "%{$query}%")
                    ->orWhereHas('user', function($u) use ($query) {
                        $u->where('name', 'like', "%{$query}%");
                    });
            });
        }

        return $q->limit(10)
            ->get()
            ->map(function($s) {
                return [
                    'id' => $s->id,
                    'label' => ($s->user->name ?? 'Unknown') . ' (' . $s->reg_no . ')',
                    'value' => $s->id
                ];
            });
    }

    /**
     * Main Dashboard Analytics
     */
    public function overview(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $range = $request->input('timeRange', '7d');

        $startDate = match ($range) {
            'today' => Carbon::today(),
            '30d' => Carbon::now()->subDays(30),
            'all' => Carbon::create(2000), // Far past
            default => Carbon::now()->subDays(7),
        };

        // --- KPIs ---
        $totalStudents = Student::where('tenant_id', $tenantId)->count();

        $activeNow = Attempt::where('tenant_id', $tenantId)
            ->where('started_at', '>=', Carbon::now()->subHours(2))
            ->whereNull('submitted_at')
            ->count();

        // CHANGED: Calculate avg based on attempt's own total_marks
        $avgPerformance = Attempt::where('tenant_id', $tenantId)
            ->where('submitted_at', '>=', $startDate)
            ->where('total_marks', '>', 0)
            ->avg(DB::raw('(score / total_marks) * 100')) ?? 0;

        // CHANGED: No join needed, check attempt columns directly
        $atRiskCount = DB::table('attempts')
            ->select('student_id', DB::raw('AVG((score / total_marks) * 100) as avg_pct'))
            ->where('tenant_id', $tenantId)
            ->where('total_marks', '>', 0)
            ->groupBy('student_id')
            ->having('avg_pct', '<', 50)
            ->count();

        // --- Trend (Percentage based) ---
        // CHANGED: No join needed
        $trendData = Attempt::where('tenant_id', $tenantId)
            ->where('submitted_at', '>=', $startDate)
            ->where('total_marks', '>', 0)
            ->selectRaw('DATE(submitted_at) as date, AVG((score / total_marks) * 100) as avg_score, COUNT(*) as attempts')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('D, M j'),
                    'avg_score' => round($item->avg_score, 1),
                    'attempts' => $item->attempts
                ];
            });

        // --- Weak Points (Global) ---
        $weakPoints = $this->getWeakPoints($tenantId, null);

        // --- Assessment Stats ---
        // We iterate assessments and calculate the avg percentage from their attempts
        $assessmentStats = Assessment::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->map(function ($assessment) use ($totalStudents) {
                // 1. Completion Rate
                $uniqueAttempters = DB::table('attempts')
                    ->where('assessment_id', $assessment->id)
                    ->distinct('student_id')
                    ->count('student_id');

                // 2. Average Percentage for this assessment
                $avgPct = Attempt::where('assessment_id', $assessment->id)
                    ->where('total_marks', '>', 0)
                    ->avg(DB::raw('(score / total_marks) * 100')) ?? 0;

                return [
                    'assessment_id' => $assessment->id,
                    'title' => $assessment->title,
                    'completion_rate' => $totalStudents > 0 ? round(($uniqueAttempters / $totalStudents) * 100) : 0,
                    'avg_score' => round($avgPct),
                    'median_score' => round($avgPct),
                    'p90_score' => 0,
                ];
            });

        // --- Student List (Overview) ---
        $students = Student::where('students.tenant_id', $tenantId)
            ->join('users', 'students.user_id', '=', 'users.id')
            ->select('students.*', 'users.name', 'users.email')
            ->withCount('attempts as total_attempts')
            ->limit(50)
            ->get()
            ->map(function ($s) use ($tenantId) {
                // Calculate average percentage for this student
                $avgPct = Attempt::where('student_id', $s->id)
                    ->where('total_marks', '>', 0)
                    ->avg(DB::raw('(score / total_marks) * 100')) ?? 0;

                $lastActive = Attempt::where('student_id', $s->id)->max('submitted_at');

                if ($s->total_attempts == 0) $status = 'Inactive';
                elseif ($avgPct < 50) $status = 'At Risk';
                elseif ($avgPct > 80) $status = 'Exceling';
                else $status = 'On Track';

                return [
                    'student_id' => $s->id,
                    'name' => $s->name,
                    'reg_no' => $s->reg_no,
                    'total_attempts' => $s->total_attempts,
                    'avg_score' => round($avgPct),
                    'last_active' => $lastActive,
                    'status' => $status,
                    'weakest_module' => null
                ];
            });

        return response()->json([
            'kpis' => [
                'total_students' => $totalStudents,
                'active_now' => $activeNow,
                'avg_performance' => round($avgPerformance),
                'at_risk_count' => $atRiskCount,
            ],
            'trend' => $trendData,
            'weak_points' => $weakPoints,
            'assessment_stats' => $assessmentStats,
            'student_performances' => $students
        ]);
    }

    /**
     * Individual Student Report
     */
    public function student(Request $request, $studentId)
    {
        $tenantId = $request->user()->tenant_id;
        $student = Student::where('tenant_id', $tenantId)->with('user')->findOrFail($studentId);

        $history = Attempt::where('attempts.student_id', $studentId)
            ->join('assessments', 'attempts.assessment_id', '=', 'assessments.id')
            ->select(
                'attempts.*',
                'assessments.title as assessment_title',
                'assessments.id as assessment_id'
            )
            ->orderBy('attempts.submitted_at', 'desc') // Usually recent first is better
            ->get()
            ->map(function ($attempt) {
                // Cohort Stats
                $cohortPct = Attempt::where('assessment_id', $attempt->assessment_id)
                    ->where('total_marks', '>', 0)
                    ->avg(DB::raw('(score / total_marks) * 100')) ?? 0;

                $total = $attempt->total_marks > 0 ? $attempt->total_marks : 1;
                $myPct = ($attempt->score / $total) * 100;

                return [
                    'id' => $attempt->id, // <--- CRITICAL FIX: Include the attempt ID
                    'assessment' => $attempt->assessment_title,
                    'score_obtained' => $attempt->score,
                    'total_mark' => $attempt->total_marks,
                    'score' => round($myPct),
                    'cohort_avg' => round($cohortPct),
                    'date' => $attempt->submitted_at?->format('Y-m-d'),
                    'duration' => $attempt->duration_sec ? gmdate("H:i:s", $attempt->duration_sec) : 'N/A'
                ];
            });

        $weakPoints = $this->getWeakPoints($tenantId, $studentId);

        // Stats
        $myGlobalAvg = $history->avg('score') ?? 0;
        $totalAttempts = $history->count();

        $allStudentAvgs = DB::table('attempts')
            ->where('tenant_id', $tenantId)
            ->where('total_marks', '>', 0)
            ->select('student_id', DB::raw('AVG((score / total_marks) * 100) as avg_pct'))
            ->groupBy('student_id')
            ->pluck('avg_pct');

        $studentsBelowMe = $allStudentAvgs->filter(fn($s) => $s < $myGlobalAvg)->count();
        $totalStudents = $allStudentAvgs->count();
        $percentile = $totalStudents > 0 ? round(($studentsBelowMe / $totalStudents) * 100) : 0;

        if($totalStudents == 1 && $totalAttempts > 0) $percentile = 100;

        return response()->json([
            'student' => [
                'name' => $student->user->name,
                'email' => $student->user->email,
                'reg_no' => $student->reg_no,
                'joined_at' => $student->created_at->format('M Y'),
                'training_status' => $student->training_status
            ],
            'stats' => [
                'avg_score' => round($myGlobalAvg),
                'total_attempts' => $totalAttempts,
                'percentile' => $percentile,
                'status' => $myGlobalAvg < 50 ? 'At Risk' : ($myGlobalAvg > 80 ? 'Exceling' : 'On Track')
            ],
            'history' => $history,
            'weak_points' => $weakPoints
        ]);
    }

    /**
     * GET /v1/reports/attempts/{id}
     */
    public function attemptDetails(Request $request, $attemptId)
    {
        $tenantId = $request->user()->tenant_id;

        $attempt = Attempt::where('tenant_id', $tenantId)
            ->with([
                'assessment',
                'responses.question.options',
                'responses.option'
            ])
            ->findOrFail($attemptId);

        $responses = $attempt->responses->map(function ($resp) {
            $q = $resp->question;

            $isCorrect = false;
            $correctText = null;

            // Find Correct Answer String
            $correctOptionObj = $q->options->where('is_correct', true)->first();
            if ($correctOptionObj) {
                $correctText = $correctOptionObj->label;
            }

            // Correctness Logic
            if ($q->type === 'MCQ' || $q->type === 'BOOLEAN') {
                if ($resp->option && $resp->option->is_correct) {
                    $isCorrect = true;
                }
            } elseif ($q->type === 'TEXT') {
                if ($resp->text_answer && $correctOptionObj) {
                    $userAns = trim(strtolower($resp->text_answer));
                    $correctAns = trim(strtolower($correctOptionObj->text));
                    if ($userAns === $correctAns) {
                        $isCorrect = true;
                    }
                }
            }

            return [
                'id' => $resp->id,
                'question' => [
                    'text' => $q->stem ?? 'Question Text',
                    'type' => $q->type,
                    'points' => $q->points,
                ],
                'option' => $resp->option ? [
                    'text' => $resp->option->label,
                    'is_correct' => (bool)$resp->option->is_correct
                ] : null,
                'text_answer' => $resp->text_answer,
                'is_correct' => $isCorrect,
                'correct_text' => $correctText,
            ];
        });

        $total = $attempt->total_marks > 0 ? $attempt->total_marks : 1;
        $pct = ($attempt->score / $total) * 100;

        return response()->json([
            'id' => $attempt->id,
            'score' => round($pct),
            'submitted_at' => $attempt->submitted_at ? $attempt->submitted_at->format('Y-m-d H:i') : 'N/A',
            'assessment' => [
                'title' => $attempt->assessment->title,
                'total_mark' => $attempt->total_marks
            ],
            'responses' => $responses
        ]);
    }

    private function getWeakPoints($tenantId, $studentId = null)
    {
        $query = DB::table('responses')
            ->join('attempts', 'responses.attempt_id', '=', 'attempts.id')
            ->join('questions', 'responses.question_id', '=', 'questions.id')
            ->join('options', 'responses.option_id', '=', 'options.id')
            ->where('attempts.tenant_id', $tenantId)
            ->whereNotNull('questions.topic');

        if ($studentId) {
            $query->where('attempts.student_id', $studentId);
        }

        return $query->select(
                'questions.topic',
                DB::raw('COUNT(*) as total_attempts'),
                DB::raw('AVG(options.is_correct) * 100 as avg_score')
            )
            ->groupBy('questions.topic')
            ->orderBy('avg_score', 'asc')
            ->limit(8)
            ->get()
            ->map(function ($item) {
                $score = round($item->avg_score);
                $difficulty = $score < 50 ? 'High' : ($score < 75 ? 'Medium' : 'Low');
                return [
                    'topic' => $item->topic,
                    'avg_score' => $score,
                    'total_attempts' => $item->total_attempts,
                    'difficulty_index' => $difficulty
                ];
            });
    }

}
