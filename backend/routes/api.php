<?php

use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\DatasetController;
use App\Http\Controllers\Api\V1\DefinitionController;
use App\Http\Controllers\Api\V1\DefinitionSuggestionController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportPdfController;
use App\Http\Controllers\Api\V1\ReportTranslationController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\VisualizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Data Speak application API  (base path /api/v1)  - Laravel API Documentation Section 4
|--------------------------------------------------------------------------
| Laravel is the only API the React frontend talks to. FastAPI is internal.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');  // AUTH-API-001
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');           // AUTH-API-002
    });

    // Signed link from the verification email (browser GET). Validity is checked in the controller so a bad
    // link redirects to the frontend with an explanation instead of returning JSON to a human.
    Route::get('/auth/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:verification')->name('auth.verify');                                 // AUTH-API-005

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');        // AUTH-API-003
        Route::get('/me', [AuthController::class, 'me'])->name('me');                               // AUTH-API-004
        Route::post('/auth/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification')->name('auth.resend');                             // AUTH-API-006

        // Everything below needs a confirmed email address (403 EMAIL_NOT_VERIFIED otherwise).
        Route::get('/analyses', [AnalysisController::class, 'index'])->middleware('verified.api')->name('analyses.index');  // ANL-API-001
        Route::post('/analyses', [AnalysisController::class, 'store'])->middleware('verified.api')->name('analyses.store'); // ANL-API-002

        Route::prefix('/analyses/{analysis}')->middleware('verified.api')->name('analyses.')->group(function (): void {
            Route::get('/', [AnalysisController::class, 'show'])->name('show');                     // ANL-API-003
            Route::delete('/', [AnalysisController::class, 'destroy'])->name('destroy');            // ANL-API-004

            Route::post('/dataset', [DatasetController::class, 'upload'])->middleware('throttle:upload')->name('dataset.upload'); // DATA-API-001
            Route::post('/validate', [DatasetController::class, 'validate'])->name('dataset.validate');                           // DATA-API-002

            Route::post('/profile', [ProfileController::class, 'run'])->name('profile.run');        // PROF-API-001
            Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');       // PROF-API-002

            Route::put('/definitions', [DefinitionController::class, 'update'])->name('definitions.update'); // DEF-API-001
            Route::post('/definitions/suggest-names', DefinitionSuggestionController::class)->middleware('throttle:ai-suggest')->name('definitions.suggest'); // DEF-API-002

            Route::post('/run', [RunController::class, 'run'])->middleware(['throttle:run', 'idempotent'])->name('run'); // RUN-API-001
            Route::get('/status', [RunController::class, 'status'])->name('status');                 // RUN-API-002

            Route::get('/insights', [InsightController::class, 'index'])->name('insights');          // INS-API-001
            Route::get('/visualizations', [VisualizationController::class, 'index'])->name('visualizations'); // VIS-API-001
            Route::get('/visualizations/options', [VisualizationController::class, 'options'])->name('visualizations.options'); // VIS-API-002
            Route::put('/visualizations/selection', [VisualizationController::class, 'select'])->name('visualizations.select'); // VIS-API-003

            Route::post('/report', [ReportController::class, 'generate'])->middleware(['throttle:report', 'idempotent'])->name('report.generate'); // RPT-API-001
            Route::get('/report', [ReportController::class, 'show'])->name('report.show');           // RPT-API-002
            Route::get('/report/pdf', ReportPdfController::class)->middleware('throttle:report')->name('report.pdf'); // RPT-API-003 (FEAT-RPT-003)
            Route::post('/report/translate', ReportTranslationController::class)->middleware('throttle:ai-suggest')->name('report.translate'); // RPT-API-004 (FEAT-I18N-003)

            Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');    // TRC-API-001
        });
    });
});
