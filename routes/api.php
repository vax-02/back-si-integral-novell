<?php

use App\Http\Controllers\CareerController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DegreeController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('careers/download-template', [CareerController::class, 'downloadTemplate']);

Route::post('login', [UserController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::put('users/change-password', [UserController::class, 'updatePassword']);

    Route::get('careers/simple', [CareerController::class, 'simple']);
    Route::post('careers/import-preview', [CareerController::class, 'importPreview']);
    Route::post('careers/import-confirm', [CareerController::class, 'importConfirm']);
    Route::post('careers/{career}/subjects', [CareerController::class, 'storeSubject']);
    Route::put('careers/{career}/subjects/{subject}', [CareerController::class, 'updateSubject']);
    Route::delete('careers/{career}/subjects/{subject}', [CareerController::class, 'deleteSubject']);


    Route::apiResource('courses', CourseController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('careers', CareerController::class);
    Route::apiResource('docentes', DocenteController::class);
    Route::put('docentes/{docente}/toggle-status',          [DocenteController::class, 'toggleStatus']);
    Route::post('docentes/{docente}/subjects',              [DocenteController::class, 'assignSubject']);
    Route::delete('docentes/{docente}/subjects/{subject}',  [DocenteController::class, 'removeSubject']);
    Route::get('subjects', [SubjectController::class, 'index']);
    Route::get('degrees', [DegreeController::class, 'index']);

    Route::put('users/{user}/profile', [UserController::class, 'updateProfile']);
    Route::put('users/{user}/change-status', [UserController::class, 'changeStatus']);
});
    
Route::get('/zip-test', function () {
    return [
        'php_binary'      => PHP_BINARY,
        'php_version'     => PHP_VERSION,
        'zip_class'       => class_exists(ZipArchive::class),
        'zip_extension'   => extension_loaded('zip'),
        'loaded_ini'      => php_ini_loaded_file(),
        'scanned_ini'     => php_ini_scanned_files(),
    ];
});

