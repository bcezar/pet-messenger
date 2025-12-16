<?php

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


