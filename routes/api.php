<?php

use App\Http\Controllers\AppointmentBusinessRuleController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentQueueController;
use App\Http\Controllers\AppointmentServiceController;
use App\Http\Controllers\AppointmentSlotController;
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
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityMiddleware;
use Illuminate\Support\Facades\Route;

// role('Registrar', 'Administrator') -> middleware string
$role = fn (string ...$roles) => RoleMiddleware::class . ':' . implode(',', $roles);

// URL parameters may only contain safe characters (anything else is a 404)
Route::pattern('id', '[A-Za-z0-9_.-]{1,80}');
Route::pattern('studentNumber', '[A-Za-z0-9_.-]{1,50}');

Route::middleware(SecurityMiddleware::class)->group(function () use ($role) {

    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // brute-force protection lives inside AuthController (per username + per IP lockout)
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureActiveUser::class, 'throttle:120,1,api'])->group(function () use ($role) {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Settings
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update'])->middleware($role('Administrator'));

        // Users + audit logs (admin)
        Route::middleware($role('Administrator'))->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store'])->middleware('throttle:10,1,writes');
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
        Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware([$role('Student'), 'throttle:10,1,writes']);
        Route::patch('/enrollments/{id}/status', [EnrollmentController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));

        // Payments
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::post('/payments', [PaymentController::class, 'store'])->middleware([$role('Student'), 'throttle:10,1,writes']);
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
        Route::post('/document-requests', [DocumentRequestController::class, 'store'])->middleware([$role('Student'), 'throttle:10,1,writes']);
        Route::patch('/document-requests/{id}/status', [DocumentRequestController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));

        // Appointment service management
        Route::get('/appointment-services', [AppointmentServiceController::class, 'index']);
        Route::post('/appointment-services', [AppointmentServiceController::class, 'store'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));
        Route::put('/appointment-services/{id}', [AppointmentServiceController::class, 'update'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));

        // Appointment business rules
        Route::get('/appointment-business-rules', [AppointmentBusinessRuleController::class, 'index'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));
        Route::post('/appointment-business-rules', [AppointmentBusinessRuleController::class, 'store'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));
        Route::put('/appointment-business-rules/{id}', [AppointmentBusinessRuleController::class, 'update'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));

        // Appointment slot management
        Route::get('/appointment-slots', [AppointmentSlotController::class, 'index']);
        Route::post('/appointment-slots', [AppointmentSlotController::class, 'store'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));
        Route::put('/appointment-slots/{id}', [AppointmentSlotController::class, 'update'])->middleware($role('Registrar', 'Department Staff', 'Administrator'));

        // Appointments
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/my', [AppointmentController::class, 'myAppointments'])->middleware($role('Student'));
        Route::get('/appointments/{id}', [AppointmentController::class, 'show']);
        Route::post('/appointments', [AppointmentController::class, 'store'])->middleware([$role('Student'), 'throttle:10,1,writes']);
        Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
        Route::patch('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule']);

        // Queue management
        Route::get('/queue-entries', [AppointmentQueueController::class, 'index'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::get('/queue-entries/{id}/summary', [AppointmentQueueController::class, 'statusSummary']);
        Route::patch('/queue-entries/{id}/check-in', [AppointmentQueueController::class, 'checkIn'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/queue-entries/{id}/override', [AppointmentQueueController::class, 'overrideStatus'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::post('/queue/next', [AppointmentQueueController::class, 'callNext'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/queue-entries/{id}/start', [AppointmentQueueController::class, 'startService'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/queue-entries/{id}/complete', [AppointmentQueueController::class, 'complete'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/queue-entries/{id}/no-show', [AppointmentQueueController::class, 'markNoShow'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
        Route::patch('/queue-entries/{id}/cancel', [AppointmentQueueController::class, 'cancel'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));

        // Notifications (always the logged-in user's own)
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    });
});
