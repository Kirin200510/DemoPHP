<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [
    DocumentController::class,
    'create',
])->name('documents.create');
Route::post('/documents', [
    DocumentController::class,
    'store',
])->middleware('throttle:10,1')->name('documents.store');

Route::get('/documents/{document}', [
    DocumentController::class,
    'show',
])->name('documents.show');

Route::get('/documents/{document}/original', [
    DocumentController::class,
    'original',
])->name('documents.original');

Route::get('/documents/{document}/processed', [
    DocumentController::class,
    'processed',
])->name('documents.processed');
