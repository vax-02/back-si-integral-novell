<?php

use App\Http\Controllers\AttendanceController;
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
use App\Http\Controllers\StudentPayController;
use App\Http\Controllers\StudentPensumController;
use App\Http\Controllers\StudentScheduleController;
use App\Http\Controllers\StudentGradeController;
use App\Http\Controllers\StudentSubjectController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Datos públicos de la institución (contacto) para la landing page
Route::get('institutions', [InstitutionController::class, 'index']);

Route::post('login', [UserController::class, 'login']);

// Rutas generales para cualquier usuario autenticado
Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
    Route::put('users/change-password', [UserController::class, 'updatePassword']);
    Route::put('users/{user}/profile', [UserController::class, 'updateProfile']);

    // Descarga de materiales compartida por Admin, Docente y Estudiante
    Route::get('materials/{material}/download', [MaterialController::class, 'download']);
});

// Rutas compartidas entre Administrador y Secretaria con URIs estáticas.
// Se registran ANTES del grupo admin para que ganen al matching contra las rutas
// salvajes {param} del apiResource (ej. /careers/simple vs /careers/{career}).
Route::middleware(['auth:sanctum', 'active.user', 'role:1,2'])->group(function () {
    Route::get('careers/simple', [CareerController::class, 'simple']);
    Route::get('parallels/{id}/first-course', [ParallelController::class, 'getFirstCourse']);
    Route::get('pays/cards', [PayController::class, 'dataCards']);
    Route::get('pays/{pay}/receipt', [PayController::class, 'receipt']);
    Route::get('students/{student}/academic-history/export', [StudentController::class, 'exportAcademicHistory']);
});

Route::middleware(['auth:sanctum', 'active.user', 'role:1'])->group(function () {
    Route::get('careers/download-template', [CareerController::class, 'downloadTemplate']);

    Route::post('careers/import-preview', [CareerController::class, 'importPreview']);
    Route::post('careers/import-confirm', [CareerController::class, 'importConfirm']);
    Route::post('careers/{career}/subjects', [CareerController::class, 'storeSubject']);
    Route::put('careers/{career}/subjects/{subject}', [CareerController::class, 'updateSubject']);
    Route::delete('careers/{career}/subjects/{subject}', [CareerController::class, 'deleteSubject']);
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::put('parallels/{parallel}/toggle-status', [ParallelController::class,'toggleStatus']);
    Route::get('parallels/{parallel}/materials', [MaterialController::class, 'materialsByParallel']);
    Route::get('parallels/{parallel}/students', [ParallelController::class, 'students']);
    Route::post('parallels/{parallel}/preview-advance', [StudentController::class, 'previewParallelAdvance']);
    Route::post('parallels/{parallel}/advance-level', [StudentController::class, 'advanceParallelLevel']);

    Route::apiResource('parallels', ParallelController::class);
    Route::apiResource('institutions', InstitutionController::class)->except(['index']);
    Route::apiResource('courses', CourseController::class);
    Route::apiResource('concepts', ConceptController::class);
    Route::apiResource('pays', PayController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('students', StudentController::class);
    Route::post('students/from-user', [StudentController::class, 'storeFromUser']);
    Route::apiResource('careers', CareerController::class);
    Route::apiResource('docentes', DocenteController::class);
    Route::post('docentes/from-user', [DocenteController::class, 'storeFromUser']);

    Route::post('student-careers',[StudentController::class, 'addCareer']);
    Route::post('students/{student}/withdraw/{career}', [StudentController::class, 'withdraw']);
    Route::post('students/{student}/reinstate/{career}', [StudentController::class, 'reinstate']);
    Route::put('students/{student}/parallel', [StudentController::class, 'updateParallel']);
    Route::put('students/{student}/toggle-status', [StudentController::class, 'toggleStatus']);
    Route::post('students/{student}/advance-level', [StudentController::class, 'advanceLevel']);
    Route::post('students/{student}/preview-advance', [StudentController::class, 'previewAdvanceLevel']);
    Route::post('students/{student}/graduate', [StudentController::class, 'graduate']);
    Route::put('careers/{career}/toggle-status', [CareerController::class, 'toggleStatus']);
    Route::put('docentes/{docente}/toggle-status',          [DocenteController::class, 'toggleStatus']);
    Route::post('docentes/{docente}/subjects',              [DocenteController::class, 'assignSubject']);
    Route::delete('docentes/{docente}/subjects/{subject}',  [DocenteController::class, 'removeSubject']);

    // Asistencia / biometría
    Route::put('docentes/{docente}/attendance-config', [AttendanceController::class, 'updateConfig']);
    Route::get('docentes/{docente}/schedules',     [AttendanceController::class, 'getSchedules']);
    Route::post('docentes/{docente}/schedules',    [AttendanceController::class, 'storeSchedule']);
    Route::delete('docente-schedules/{schedule}',  [AttendanceController::class, 'destroySchedule']);
    Route::post('attendance/import',               [AttendanceController::class, 'importAttendance']);
    Route::get('attendance/validate',              [AttendanceController::class, 'validateAttendance']);
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

    Route::get('materials', [MaterialController::class, 'index']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::put('materials/{id}', [MaterialController::class, 'update']);
    Route::delete('materials/{id}', [MaterialController::class, 'destroy']);

    Route::get('grades/years', [GradeController::class, 'years']);
    Route::get('grades/export', [GradeController::class, 'exportCalificaciones']);
    Route::get('grades/parallel/{parallel}/general', [GradeController::class, 'generalByParallel']);
    Route::get('grades/parcial-report', [GradeController::class, 'parcialReport']);
    Route::get('grades/parcial-report/export', [GradeController::class, 'exportParcialReport']);

    Route::put('users/{user}/change-status', [UserController::class, 'changeStatus']);
    Route::put('users/{user}/roles', [UserController::class, 'syncRoles']);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword']);
});

// Rutas compartidas entre Administrador y Secretaria con URI duplicada del apiResource.
// Se registran DESPUÉS del grupo admin para reemplazar las versiones role:1
// (la secretaria solo inscribe estudiantes y cobra; no edita/elimina ni anula).
Route::middleware(['auth:sanctum', 'active.user', 'role:1,2'])->group(function () {
    // Inscripción de estudiantes (solo registrar)
    Route::get('students', [StudentController::class, 'index']);
    Route::post('students', [StudentController::class, 'store']);

    // Cobros (cobrar, sin anular)
    Route::get('pays', [PayController::class, 'index']);
    Route::post('pays', [PayController::class, 'store']);
    Route::get('concepts', [ConceptController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'active.user', 'role:3'])->group(function () {
    Route::get('docente/my-subjects', [DocenteController::class, 'mySubjects']);

    Route::get('grades/students/{parallel}', [GradeController::class, 'getStudents']);
    Route::post('grades/save', [GradeController::class, 'saveGrade']);
    Route::post('grades/publish', [GradeController::class, 'publish']);
    Route::post('grades/unpublish', [GradeController::class, 'unpublish']);
    Route::post('grades/columns', [GradeController::class, 'saveColumn']);
    Route::put('grades/columns/{id}', [GradeController::class, 'updateColumn']);
    Route::delete('grades/columns/{id}', [GradeController::class, 'deleteColumn']);
    Route::post('grades/save-recovery', [GradeController::class, 'saveRecoveryGrade']);
    Route::put('grades/subject-config', [GradeController::class, 'updateSubjectConfig']);
    Route::get('grades/parcial-report', [GradeController::class, 'parcialReport']);
    Route::get('grades/parcial-report/export', [GradeController::class, 'exportParcialReport']);

    Route::get('materials', [MaterialController::class, 'index']);
    Route::post('materials', [MaterialController::class, 'store']);
    Route::put('materials/{id}', [MaterialController::class, 'update']);
    Route::delete('materials/{id}', [MaterialController::class, 'destroy']);

    Route::get('attendance/my-attendance', [AttendanceController::class, 'myAttendance']);
});

Route::middleware(['auth:sanctum', 'active.user', 'role:4'])->group(function () {
    Route::get('student/my-pensum', [StudentPensumController::class, 'myPensum']);
    Route::get('student/my-schedule', [StudentScheduleController::class, 'mySchedule']);
    Route::get('student/my-subjects', [StudentSubjectController::class, 'mySubjects']);
    Route::get('student/my-grades', [StudentGradeController::class, 'myGrades']);
    Route::get('student/my-pays', [StudentPayController::class, 'myPays']);
    Route::get('student/materials', [MaterialController::class, 'studentMaterials']);
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
