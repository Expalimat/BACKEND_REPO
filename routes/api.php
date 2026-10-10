<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClearanceController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\Route;

// role('Registrar', 'Administrator') -> middleware string
$role = fn (string ...$roles) => RoleMiddleware::class . ':' . implode(',', $roles);

Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

Route::middleware('auth:sanctum')->group(function () use ($role) {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update'])->middleware($role('Administrator'));

    // Users + audit logs (admin)
    Route::middleware($role('Administrator'))->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    // Students
    Route::get('/students', [StudentController::class, 'index'])
        ->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
    Route::get('/students/{studentNumber}', [StudentController::class, 'show']);

    // Terms, offerings, enrollments
    Route::get('/terms', [EnrollmentController::class, 'terms']);
    Route::get('/offerings', [EnrollmentController::class, 'offerings']);
    Route::post('/offerings', [EnrollmentController::class, 'storeOffering'])->middleware($role('Department Staff', 'Administrator'));
    Route::put('/offerings/{id}', [EnrollmentController::class, 'updateOffering'])->middleware($role('Department Staff', 'Administrator'));
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware($role('Student'));
    Route::patch('/enrollments/{id}/status', [EnrollmentController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store'])->middleware($role('Student'));
    Route::patch('/payments/{id}/verify', [PaymentController::class, 'verify'])->middleware($role('Cashier', 'Administrator'));

    // Clearances
    Route::get('/clearances', [ClearanceController::class, 'index']);
    Route::patch('/clearances/{id}/sign', [ClearanceController::class, 'sign'])
        ->middleware($role('Department Staff', 'Cashier', 'Registrar', 'Administrator'));

    // Grades
    Route::get('/grades', [GradeController::class, 'index']);
    Route::post('/grades', [GradeController::class, 'store'])->middleware($role('Department Staff', 'Administrator'));
    Route::patch('/grades/{id}/validate', [GradeController::class, 'validateGrade'])->middleware($role('Registrar', 'Administrator'));

    // Document requests
    Route::get('/document-requests', [DocumentRequestController::class, 'index']);
    Route::post('/document-requests', [DocumentRequestController::class, 'store'])->middleware($role('Student'));
    Route::patch('/document-requests/{id}/status', [DocumentRequestController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));

    // Notifications (always the logged-in user's own)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
});
