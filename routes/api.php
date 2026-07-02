<?php

use App\Http\Controllers\DegreeController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('login', [UserController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::put('users/change-password', [UserController::class, 'updatePassword']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('docentes', DocenteController::class);
    Route::get('degrees', [DegreeController::class, 'index']);

    Route::put('users/{user}/profile', [UserController::class, 'updateProfile']);
    Route::put('users/{user}/change-status', [UserController::class, 'changeStatus']);
});
