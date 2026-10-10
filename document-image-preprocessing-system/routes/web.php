<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\QuickLoginController;
use Illuminate\Support\Facades\Route;

Route::post('/login/quick/{role}', QuickLoginController::class)
    ->middleware('throttle:10,1')
    ->name('login.quick');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/', [DocumentController::class, 'create'])
        ->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])
        ->middleware('throttle:10,1')
        ->can('create', 'App\\Models\\ImageDocument')
        ->name('documents.store');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
        ->can('view', 'document')
        ->name('documents.show');
    Route::get('/documents/{document}/original', [DocumentController::class, 'original'])
        ->can('view', 'document')
        ->name('documents.original');
    Route::get('/documents/{document}/processed', [DocumentController::class, 'processed'])
        ->can('view', 'document')
        ->name('documents.processed');
    Route::get('/documents/{document}/face-crop', [DocumentController::class, 'faceCrop'])
        ->can('viewFaceCrop', 'document')
        ->name('documents.face-crop');

    Route::put('/documents/{document}/ocr', [DocumentController::class, 'updateStructuredOcr'])
        ->can('updateStructuredOcr', 'document')
        ->name('documents.ocr.update');
    Route::post('/documents/{document}/submit-review', [DocumentController::class, 'submitForReview'])
        ->can('submitForReview', 'document')
        ->name('documents.submit-review');
    Route::post('/documents/{document}/verify', [DocumentController::class, 'verify'])
        ->can('review', 'document')
        ->name('documents.verify');
    Route::post('/documents/{document}/request-resubmission', [DocumentController::class, 'requestResubmission'])
        ->can('review', 'document')
        ->name('documents.request-resubmission');

    Route::prefix('admin')->middleware('role:admin')->name('admin.')->group(function (): void {
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::put('/users/{user}/access', [AdminController::class, 'updateAccess'])->name('users.access');
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
    });
});
