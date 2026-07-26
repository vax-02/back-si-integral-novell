<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CareerController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DegreeController;
use App\Http\Controllers\DocenteController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\ParallelController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPensumController;
use App\Http\Controllers\StudentScheduleController;
use App\Http\Controllers\StudentGradeController;
use App\Http\Controllers\StudentSubjectController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UserController;
use App\Models\Institution;
use Illuminate\Support\Facades\Route;

Route::get('careers/download-template', [CareerController::class, 'downloadTemplate']);

Route::post('login', [UserController::class, 'login']);

// Receipt route outside auth middleware (uses token query param)

Route::middleware('auth:sanctum')->group(function () {
    Route::put('users/change-password', [UserController::class, 'updatePassword']);
    
    
    Route::get('pays/{pay}/receipt', [PayController::class,'receipt']);
    Route::get('careers/simple', [CareerController::class, 'simple']);
    Route::post('careers/import-preview', [CareerController::class, 'importPreview']);
    Route::post('careers/import-confirm', [CareerController::class, 'importConfirm']);
    Route::post('careers/{career}/subjects', [CareerController::class, 'storeSubject']);
    Route::put('careers/{career}/subjects/{subject}', [CareerController::class, 'updateSubject']);
    Route::delete('careers/{career}/subjects/{subject}', [CareerController::class, 'deleteSubject']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('pays/cards', [PayController::class,'dataCards']);
    Route::get('parallels/{id}/first-course', [ParallelController::class,'getFirstCourse']);
    

    Route::apiResource('parallels/', ParallelController::class);
    Route::apiResource('institutions', InstitutionController::class);
    Route::apiResource('courses', CourseController::class);
    Route::apiResource('concepts', ConceptController::class);
    Route::apiResource('pays', PayController::class);


    Route::post('student-careers',[StudentController::class, 'addCareer']);
    Route::apiResource('users', UserController::class);
    Route::post('students/{student}/withdraw/{career}', [StudentController::class, 'withdraw']);
    Route::post('students/{student}/reinstate/{career}', [StudentController::class, 'reinstate']);
    Route::put('students/{student}/parallel', [StudentController::class, 'updateParallel']);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('careers', CareerController::class);
    Route::put('careers/{career}/toggle-status', [CareerController::class, 'toggleStatus']);
    Route::apiResource('docentes', DocenteController::class);
    Route::put('docentes/{docente}/toggle-status',          [DocenteController::class, 'toggleStatus']);
    Route::post('docentes/{docente}/subjects',              [DocenteController::class, 'assignSubject']);
    Route::delete('docentes/{docente}/subjects/{subject}',  [DocenteController::class, 'removeSubject']);
    Route::get('subjects', [SubjectController::class, 'index']);
    Route::get('subjects/{subject}/detail', [SubjectController::class, 'detail']);
    Route::get('subjects/{subject}/history', [SubjectController::class, 'history']);
    Route::post('subjects/{subject}/assign-docente', [SubjectController::class, 'assignDocente']);
    Route::post('subjects/{subject}/remove-docente', [SubjectController::class, 'removeDocente']);
    Route::get('degrees', [DegreeController::class, 'index']);
    Route::get('subjects/{career}/by-career', [ScheduleController::class, 'subjectsByCareer']);
    Route::get('schedules/parallel/{parallel}', [ScheduleController::class, 'getByParallel']);
    Route::post('schedules/save', [ScheduleController::class, 'save']);
    Route::post('schedules', [ScheduleController::class, 'store']);
    Route::put('schedules/{id}', [ScheduleController::class, 'update']);
    Route::delete('schedules/{id}', [ScheduleController::class, 'destroy']);
    
    Route::get('student/my-pensum', [StudentPensumController::class, 'myPensum']);
    Route::get('student/my-schedule', [StudentScheduleController::class, 'mySchedule']);
    Route::get('student/my-subjects', [StudentSubjectController::class, 'mySubjects']);
    Route::get('student/my-grades', [StudentGradeController::class, 'myGrades']);
    Route::get('docente/my-subjects', [DocenteController::class, 'mySubjects']);
    Route::get('materials', [MaterialController::class, 'index']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::get('materials/{material}/download', [MaterialController::class, 'download']);
    Route::delete('materials/{id}', [MaterialController::class, 'destroy']);

    Route::get('grades/students/{parallel}', [GradeController::class, 'getStudents']);
    Route::post('grades/save', [GradeController::class, 'saveGrade']);
    Route::post('grades/publish', [GradeController::class, 'publish']);
    Route::post('grades/unpublish', [GradeController::class, 'unpublish']);
    Route::post('grades/columns', [GradeController::class, 'saveColumn']);
    Route::put('grades/columns/{id}', [GradeController::class, 'updateColumn']);
    Route::delete('grades/columns/{id}', [GradeController::class, 'deleteColumn']);

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

