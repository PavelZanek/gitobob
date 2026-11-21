<?php

use App\Http\Controllers\CloudController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::view('/settings', 'settings')->name('settings');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/workspace', [WorkspaceController::class, 'choose'])->name('workspace.choose');

Route::get('/repository', RepositoryController::class)->name('repository');
Route::get('/repository/branches', [IndexController::class, 'branches'])->name('repository.branches');
Route::post('/repository/branches', [IndexController::class, 'changeBranch'])->name('repository.branches.change');
Route::post('/repository/publish', [IndexController::class, 'sync'])->defaults('operation', 'publish')->name('repository.publish');
Route::post('/repository/index', [IndexController::class, 'change'])->name('repository.index');
Route::get('/repository/commit', [IndexController::class, 'preview'])->name('repository.commit.preview');
Route::post('/repository/commit', [IndexController::class, 'commit'])->name('repository.commit');
Route::get('/repository/push', [IndexController::class, 'pushPreview'])->name('repository.push.preview');
Route::post('/repository/push', [IndexController::class, 'sync'])->defaults('operation', 'push')->name('repository.push');
Route::post('/repository/fetch', [IndexController::class, 'sync'])->defaults('operation', 'fetch')->name('repository.fetch');
Route::post('/repository/pull', [IndexController::class, 'sync'])->defaults('operation', 'pull')->name('repository.pull');
Route::get('/changes', [RepositoryController::class, 'changes'])->name('changes');

Route::get('/cloud', [CloudController::class, 'index'])->name('cloud');
Route::get('/cloud/data', [CloudController::class, 'data'])->name('cloud.data');
Route::get('/cloud/pulls/{number}', [CloudController::class, 'pull'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull');
Route::post('/cloud/pulls/{number}/open', [CloudController::class, 'openPull'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull.open');
Route::post('/cloud/pulls/{number}/viewed', [CloudController::class, 'viewed'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull.viewed');

Route::post('/cloud/pulls/{number}/comments', [CloudController::class, 'comment'])->where('number', '[1-9][0-9]{0,9}')->name('cloud.pull.comment');
