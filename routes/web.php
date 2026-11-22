<?php

use App\Http\Controllers\CloudController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\ConflictController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StashController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::view('/settings', 'settings')->name('settings');
Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/workspace', [WorkspaceController::class, 'choose'])->name('workspace.choose');

Route::get('/repository', RepositoryController::class)->name('repository');
Route::get('/repository/compare', [CompareController::class, 'index'])->name('repository.compare');
Route::match(['get', 'post'], '/repository/compare/action', [CompareController::class, 'action'])->name('repository.compare.action');
Route::match(['get', 'post'], '/repository/compare/compose', [CompareController::class, 'compose'])->name('repository.compare.compose');
Route::get('/repository/history', HistoryController::class)->name('repository.history');
Route::get('/repository/branches', [IndexController::class, 'branches'])->name('repository.branches');
Route::post('/repository/branches', [IndexController::class, 'changeBranch'])->name('repository.branches.change');
Route::get('/repository/conflicts', [ConflictController::class, 'index'])->name('repository.conflicts');
Route::post('/repository/conflicts/editor', [ConflictController::class, 'editor'])->name('repository.conflicts.editor');
Route::post('/repository/conflicts/resolve', [ConflictController::class, 'resolve'])->name('repository.conflicts.resolve');
Route::get('/repository/stashes', [StashController::class, 'index'])->name('repository.stashes');
Route::get('/repository/stash-switch', [StashController::class, 'preview'])->name('repository.stash.preview');
Route::post('/repository/stash-switch', [StashController::class, 'change'])->defaults('operation', 'save')->name('repository.stash.save');
Route::post('/repository/stashes/apply', [StashController::class, 'change'])->defaults('operation', 'apply')->name('repository.stash.apply');
Route::post('/repository/publish', [IndexController::class, 'publish'])->name('repository.publish');
Route::post('/repository/publish/open', [IndexController::class, 'openComparison'])->name('repository.publish.open');
Route::post('/repository/index', [IndexController::class, 'change'])->name('repository.index');
Route::post('/repository/amend/preview', [IndexController::class, 'amend'])->name('repository.amend.preview');
Route::post('/repository/amend', [IndexController::class, 'amend'])->name('repository.amend');
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

Route::prefix('/cloud/pulls/{number}')->where(['number' => '[1-9][0-9]{0,9}'])->name('cloud.pull.')->group(function (): void {
    Route::get('/threads', [CloudController::class, 'threads'])->name('threads');
    Route::post('/threads', [CloudController::class, 'threadAction'])->name('threads.action');
    Route::get('/summary', [CloudController::class, 'summary'])->name('summary');
    Route::post('/ready', [CloudController::class, 'ready'])->name('ready');
    Route::get('/lines', [CloudController::class, 'lines'])->name('lines');
    Route::post('/lines', [CloudController::class, 'postLine'])->name('lines.post');
});
