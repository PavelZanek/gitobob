<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::post('/workspace', [WorkspaceController::class, 'choose'])->name('workspace.choose');
