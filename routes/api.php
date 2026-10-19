<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentServiceController;
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
$role = fn (string ...$roles) => RoleMiddleware::class.':'.implode(',', $roles);
$appointmentStaff = $role('Registrar', 'Department Staff', 'Administrator');
$appointmentAdmin = $role('Administrator');

Route::get('/', fn () => response()->json([
    'status' => 'ok',
    'message' => 'PnC-OESS API is running.',
]));
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/appointment-queue/display', [AppointmentController::class, 'display'])
    ->middleware('appointment.response');

Route::middleware('auth:sanctum')->group(function () use ($role, $appointmentStaff, $appointmentAdmin) {
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

    // Appointment scheduling and queue management
    Route::middleware('appointment.response')->group(function () use ($role, $appointmentStaff, $appointmentAdmin) {
        Route::get('/services', [AppointmentServiceController::class, 'index'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::get('/staff/services/{serviceId}/staff', [AppointmentServiceController::class, 'staffAssignments'])
            ->middleware($appointmentAdmin);
        Route::put('/staff/services/{serviceId}/staff', [AppointmentServiceController::class, 'updateStaffAssignments'])
            ->middleware($appointmentAdmin);
        Route::get('/staff/appointments', [AppointmentController::class, 'staffAppointments'])
            ->middleware($appointmentStaff);
        Route::get('/staff/appointment-slots', [AppointmentServiceController::class, 'staffSlots'])
            ->middleware($appointmentStaff);
        Route::post('/staff/appointment-slots', [AppointmentServiceController::class, 'createStaffSlot'])
            ->middleware($appointmentAdmin);
        Route::patch('/staff/appointment-slots/{id}', [AppointmentServiceController::class, 'updateSlot'])
            ->middleware($appointmentAdmin);
        Route::get('/staff/queue', [AppointmentController::class, 'staffQueue'])
            ->middleware($appointmentStaff);
        Route::post('/staff/appointments/{id}/check-in', [AppointmentController::class, 'checkIn'])
            ->middleware($appointmentStaff);
        Route::post('/staff/queue/{queueEntryId}/call', [AppointmentController::class, 'callQueueEntry'])
            ->middleware($appointmentStaff);
        Route::post('/staff/queue/{queueEntryId}/start', [AppointmentController::class, 'startQueueEntry'])
            ->middleware($appointmentStaff);
        Route::post('/staff/queue/{queueEntryId}/complete', [AppointmentController::class, 'completeQueueEntry'])
            ->middleware($appointmentStaff);
        Route::post('/staff/queue/{queueEntryId}/no-show', [AppointmentController::class, 'noShowQueueEntry'])
            ->middleware($appointmentStaff);
        Route::post('/staff/queue/{queueEntryId}/override', [AppointmentController::class, 'overrideQueueEntry'])
            ->middleware($appointmentStaff);
        Route::get('/staff/reports/appointments', [AppointmentController::class, 'appointmentReport'])
            ->middleware($appointmentStaff);
        Route::get('/appointment-services', [AppointmentServiceController::class, 'index'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::get('/appointment-slots', [AppointmentServiceController::class, 'studentSlots'])
            ->middleware($role('Student'));
        Route::get('/appointment-services/{serviceId}/slots', [AppointmentServiceController::class, 'serviceSlots'])
            ->middleware($appointmentStaff);
        Route::post('/appointment-services', [AppointmentServiceController::class, 'store'])
            ->middleware($appointmentAdmin);
        Route::put('/appointment-services/{id}', [AppointmentServiceController::class, 'update'])
            ->middleware($appointmentAdmin);
        Route::post('/appointment-services/{serviceId}/slots', [AppointmentServiceController::class, 'createSlot'])
            ->middleware($appointmentAdmin);
        Route::patch('/appointment-slots/{id}', [AppointmentServiceController::class, 'updateSlot'])
            ->middleware($appointmentAdmin);
        Route::get('/appointment-services/{serviceId}/closures', [AppointmentServiceController::class, 'closures'])
            ->middleware($appointmentStaff);
        Route::post('/appointment-services/{serviceId}/closures', [AppointmentServiceController::class, 'closeDate'])
            ->middleware($appointmentAdmin);
        Route::delete('/appointment-services/{serviceId}/closures/{closureId}', [AppointmentServiceController::class, 'reopenDate'])
            ->middleware($appointmentAdmin);
        Route::get('/appointments', [AppointmentController::class, 'index'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::get('/appointments/my', [AppointmentController::class, 'index'])
            ->middleware($role('Student'));
        Route::post('/appointments', [AppointmentController::class, 'store'])->middleware($role('Student'));
        Route::get('/appointments/{id}', [AppointmentController::class, 'show'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::get('/appointments/{id}/history', [AppointmentController::class, 'history'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::patch('/appointments/{id}/cancel', [AppointmentController::class, 'cancel'])
            ->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::patch('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule'])
            ->middleware($role('Student'));
        Route::get('/appointment-queue/{serviceId}', [AppointmentController::class, 'queue'])
            ->middleware($appointmentStaff);
        Route::post('/appointment-queue/{serviceId}/call-next', [AppointmentController::class, 'callNext'])
            ->middleware($appointmentStaff);
        Route::post('/appointment-queue/{serviceId}/override-call', [AppointmentController::class, 'overrideCall'])
            ->middleware($appointmentStaff);
        Route::patch('/appointments/{id}/check-in', [AppointmentController::class, 'checkIn'])
            ->middleware($appointmentStaff);
        Route::post('/appointments/{id}/check-in', [AppointmentController::class, 'checkIn'])
            ->middleware($appointmentStaff);
        Route::patch('/appointments/{id}/start', [AppointmentController::class, 'start'])
            ->middleware($appointmentStaff);
        Route::patch('/appointments/{id}/recall', [AppointmentController::class, 'recall'])
            ->middleware($appointmentStaff);
        Route::patch('/appointments/{id}/finish', [AppointmentController::class, 'finish'])
            ->middleware($appointmentStaff);
    });
});
