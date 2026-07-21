<?php

use App\Http\Controllers\WebPushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

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
