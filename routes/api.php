<?php
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuditUploadController;
use App\Http\Controllers\Api\AuditDataController;

Route::post('/upload-audit', [AuditUploadController::class, 'upload']);

Route::get('/audits', [AuditDataController::class, 'index']);
Route::get('/audits/{id}', [AuditDataController::class, 'show']);
