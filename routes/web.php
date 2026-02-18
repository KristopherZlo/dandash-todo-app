<?php

use App\Http\Controllers\Api\ListItemController;
use App\Http\Controllers\Api\ProfileSettingsController;
use App\Http\Controllers\Api\SharingController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', AppController::class)->name('dashboard');

    Route::prefix('/api')->group(function () {
        Route::get('/sync/state', [SharingController::class, 'state']);
        Route::post('/sync/default-owner', [SharingController::class, 'setDefaultOwner']);
        Route::get('/users/search', [SharingController::class, 'searchUsers']);
        Route::post('/invitations', [SharingController::class, 'sendInvitation']);
        Route::post('/invitations/{invitation}/accept', [SharingController::class, 'acceptInvitation']);
        Route::post('/invitations/{invitation}/decline', [SharingController::class, 'declineInvitation']);
        Route::post('/links/{link}/set-mine', [SharingController::class, 'setListAsMine']);
        Route::delete('/links/{link}', [SharingController::class, 'destroyLink']);

        Route::get('/items', [ListItemController::class, 'index']);
        Route::get('/items/suggestions', [ListItemController::class, 'suggestions']);
        Route::get('/items/suggestions/stats', [ListItemController::class, 'productStats']);
        Route::post('/items/suggestions/dismiss', [ListItemController::class, 'dismissSuggestion']);
        Route::post('/items/suggestions/reset', [ListItemController::class, 'resetSuggestionData']);
        Route::post('/items', [ListItemController::class, 'store']);
        Route::post('/items/reorder', [ListItemController::class, 'reorder']);
        Route::patch('/items/{item}', [ListItemController::class, 'update']);
        Route::delete('/items/{item}', [ListItemController::class, 'destroy']);

        Route::patch('/profile', [ProfileSettingsController::class, 'update']);
        Route::put('/profile/password', [ProfileSettingsController::class, 'updatePassword']);
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
