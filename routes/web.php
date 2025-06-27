<?php

use App\Http\Controllers\Api\AuditDataController;
use App\Http\Controllers\Api\AuditUploadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Web route for test page
Route::get('/audit-test', function () {
    return view('audit_test');
})->name('audit.test');

// API routes
Route::prefix('api')->middleware('api')->group(function () {
    Route::post('/upload-audit', [AuditUploadController::class, 'upload'])->name('upload-audit');
    Route::get('/audits', [AuditDataController::class, 'index']);
    Route::get('/audits/{id}', [AuditDataController::class, 'show']);
});
Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('audit-upload', function () {
    return Inertia::render('AuditUpload');
})->middleware(['auth', 'verified'])->name('audit-upload');

Route::get('audit-viewer', function () {
    return Inertia::render('AuditViewer');
})->middleware(['auth', 'verified'])->name('audit-viewer');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/api.php';

