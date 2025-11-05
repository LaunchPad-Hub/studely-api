<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
  AuthController, OtpController, TenantController, StudentController, StudentImportController,
  ModuleController, AssessmentController, QuestionController, QuestionOptionController,
  AttemptController, EvaluationController, ReportController,
    RubricController
};
use App\Http\Middleware\ScopeTenant;

Route::prefix('v1')->group(function () {
    // Auth
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/otp/request', [AuthController::class, 'otpRequest']);
    Route::post('/otp/verify', [AuthController::class, 'otpVerify']);

    Route::middleware(['auth:sanctum', ScopeTenant::class])->group(function () {

        // User
        Route::get('user', [AuthController::class, 'me']);

        // Tenants (SuperAdmin only)
        Route::apiResource('tenants', TenantController::class)->middleware('role:SuperAdmin');

        // Students
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::post('students/import', [StudentImportController::class, 'import']);
        Route::get('students/{id}', [StudentController::class, 'show']);
        Route::patch('students/{id}', [StudentController::class, 'update']);
        Route::delete('students/{id}', [StudentController::class, 'destroy']);

        // Modules & Assessments
        Route::apiResource('modules', ModuleController::class);
        Route::apiResource('assessments', AssessmentController::class);

        // Questions & Options
        Route::apiResource('questions', QuestionController::class);
        Route::post('questions/{id}/options', [QuestionOptionController::class, 'store']);
        Route::delete('options/{id}', [QuestionOptionController::class, 'destroy']);

        // Attempts (student)
        Route::post('assessments/{id}/attempts', [AttemptController::class, 'start']);
        Route::post('attempts/{id}/save', [AttemptController::class, 'saveProgress']);
        Route::post('attempts/{id}/submit', [AttemptController::class, 'submit']);

        // Evaluation (evaluator)
        Route::get('evaluate/queue', [EvaluationController::class, 'queue']);
        Route::post('attempts/{id}/scores', [EvaluationController::class, 'score']);

        // Rubrics
        Route::get('rubrics', [RubricController::class,'index']);
        Route::post('rubrics', [RubricController::class,'store']);
        Route::get('rubrics/{id}', [RubricController::class,'show']);
        Route::patch('rubrics/{id}', [RubricController::class,'update']);
        Route::delete('rubrics/{id}', [RubricController::class,'destroy']);

        Route::post('rubrics/criteria', [RubricController::class,'storeCriterion']);
        Route::patch('rubrics/criteria/{id}', [RubricController::class,'updateCriterion']);
        Route::delete('rubrics/criteria/{id}', [RubricController::class,'destroyCriterion']);

        // Reports
        Route::get('reports/student/{studentId}', [ReportController::class, 'student']);
        Route::get('reports/overview', [ReportController::class, 'overview']);
    });
});
