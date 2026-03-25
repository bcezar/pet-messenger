<?php

use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\VonageWebhookController;

Route::apiResource('tasks', TaskController::class);

Route::post('/send-message', [MessageController::class, 'send']);

Route::post('/twilio/webhook', [WebhookController::class, 'handle']);

Route::post('whatsapp/webhook', [WebhookController::class, 'handle']);

Route::post('/vonage/webhook', [VonageWebhookController::class, 'inbound']);
Route::post('/vonage/status', [VonageWebhookController::class, 'status']);

// Inbox routes (human attendance)
Route::middleware('auth:sanctum')->prefix('inbox')->group(function () {
    Route::get('/', [InboxController::class, 'index']);
    Route::get('/{id}', [InboxController::class, 'show']);
    Route::post('/{id}/lock', [InboxController::class, 'lock']);
    Route::post('/{id}/unlock', [InboxController::class, 'unlock']);
    Route::post('/{id}/transfer-to-human', [InboxController::class, 'transferToHuman']);
    Route::post('/{id}/transfer-to-bot', [InboxController::class, 'transferToBot']);
    Route::post('/{id}/close', [InboxController::class, 'close']);
});

