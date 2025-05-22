<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\WebhookController;

Route::apiResource('tasks', TaskController::class);

Route::post('/send-message', [MessageController::class, 'send']);

Route::post('/twilio/webhook', [WebhookController::class, 'handle']);


