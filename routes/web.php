<?php

use App\Http\Controllers\SquadInviteController;
use App\Http\Controllers\WebPushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/squad-invites/{token}', [SquadInviteController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('squad-invites.show');
Route::post('/squad-invites/{token}', [SquadInviteController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:10,1')
    ->name('squad-invites.accept');

Route::middleware(['web', 'auth'])->prefix('admin/webpush')->group(function (): void {
    Route::get('/vapid-public-key', [WebPushSubscriptionController::class, 'vapidPublicKey'])
        ->name('webpush.vapid-public-key');
    Route::post('/subscribe', [WebPushSubscriptionController::class, 'store'])
        ->name('webpush.subscribe');
    Route::delete('/subscribe', [WebPushSubscriptionController::class, 'destroy'])
        ->name('webpush.unsubscribe');
    Route::delete('/subscriptions', [WebPushSubscriptionController::class, 'destroyAll'])
        ->name('webpush.unsubscribe-all');
    Route::post('/test', [WebPushSubscriptionController::class, 'test'])
        ->name('webpush.test');
});
