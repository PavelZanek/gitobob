<?php

use App\Http\Controllers\CloudController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::post('/workspace', [WorkspaceController::class, 'choose'])->name('workspace.choose');

Route::get('/repository', RepositoryController::class)->name('repository');
Route::get('/changes', [RepositoryController::class, 'changes'])->name('changes');

Route::get('/cloud', [CloudController::class, 'index'])->name('cloud');
Route::get('/cloud/data', [CloudController::class, 'data'])->name('cloud.data');
