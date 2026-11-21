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
Route::get('/cloud/pulls/{number}', [CloudController::class, 'pull'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull');
Route::post('/cloud/pulls/{number}/open', [CloudController::class, 'openPull'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull.open');
Route::post('/cloud/pulls/{number}/viewed', [CloudController::class, 'viewed'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull.viewed');
