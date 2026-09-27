<?php

use App\Http\AuthController;
use App\Http\AuthInput;
use App\Http\Middleware\AuthenticatedSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::get('/sanctum/csrf-cookie', function (Request $request) {
    return AuthInput::check($request) ?? app(CsrfCookieController::class)->show($request);
});
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/api/me', [AuthController::class, 'me'])->middleware(AuthenticatedSession::class);
