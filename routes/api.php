<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — PolicyFlow
|--------------------------------------------------------------------------
|
| All routes follow REST conventions.
| No authentication required for this assignment.
|
*/

// Policy Request endpoints
Route::post('/policy-requests',               [PolicyRequestController::class, 'store']);
Route::get('/policy-requests',                [PolicyRequestController::class, 'index']);
Route::get('/policy-requests/{policyRequest}', [PolicyRequestController::class, 'show']);
Route::post('/policy-requests/{policyRequest}/submit', [PolicyRequestController::class, 'submit']);

// Mock insurer callback endpoint
Route::post('/mock-insurer/callback', [MockInsurerController::class, 'callback']);
