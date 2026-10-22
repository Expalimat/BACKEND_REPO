<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClearanceController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PublicVirtualQueueController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\QueueServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\Route;

$role = fn (string ...$roles) => RoleMiddleware::class.':'.implode(',', $roles);
$queueStaff = $role('Registrar', 'Department Staff', 'Administrator');
$queueAdmin = $role('Administrator');

Route::get('/', fn () => response()->json(['status' => 'ok', 'message' => 'PnC-OESS API is running.']));
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/queue/display', [QueueController::class, 'display']);

Route::prefix('public/virtual-queue')->group(function () {
    Route::get('/services', [PublicVirtualQueueController::class, 'services'])->middleware('throttle:30,1');
    Route::post('/tickets', [PublicVirtualQueueController::class, 'join'])->middleware('throttle:10,1');
    Route::post('/tickets/retrieve', [PublicVirtualQueueController::class, 'retrieve'])->middleware('throttle:10,1');
    Route::patch('/tickets/cancel', [PublicVirtualQueueController::class, 'cancel'])->middleware('throttle:10,1');
});

Route::middleware('auth:sanctum')->group(function () use ($role, $queueStaff, $queueAdmin) {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update'])->middleware($queueAdmin);

    Route::middleware($queueAdmin)->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::patch('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    Route::get('/students', [StudentController::class, 'index'])->middleware($role('Registrar', 'Cashier', 'Department Staff', 'Administrator'));
    Route::get('/students/{studentNumber}', [StudentController::class, 'show']);
    Route::get('/terms', [EnrollmentController::class, 'terms']);
    Route::get('/offerings', [EnrollmentController::class, 'offerings']);
    Route::post('/offerings', [EnrollmentController::class, 'storeOffering'])->middleware($role('Department Staff', 'Administrator'));
    Route::put('/offerings/{id}', [EnrollmentController::class, 'updateOffering'])->middleware($role('Department Staff', 'Administrator'));
    Route::get('/enrollments', [EnrollmentController::class, 'index']);
    Route::post('/enrollments', [EnrollmentController::class, 'store'])->middleware($role('Student'));
    Route::patch('/enrollments/{id}/status', [EnrollmentController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store'])->middleware($role('Student'));
    Route::patch('/payments/{id}/verify', [PaymentController::class, 'verify'])->middleware($role('Cashier', 'Administrator'));
    Route::get('/clearances', [ClearanceController::class, 'index']);
    Route::patch('/clearances/{id}/sign', [ClearanceController::class, 'sign'])->middleware($role('Department Staff', 'Cashier', 'Registrar', 'Administrator'));
    Route::get('/grades', [GradeController::class, 'index']);
    Route::post('/grades', [GradeController::class, 'store'])->middleware($role('Department Staff', 'Administrator'));
    Route::patch('/grades/{id}/validate', [GradeController::class, 'validateGrade'])->middleware($role('Registrar', 'Administrator'));
    Route::get('/document-requests', [DocumentRequestController::class, 'index']);
    Route::post('/document-requests', [DocumentRequestController::class, 'store'])->middleware($role('Student'));
    Route::patch('/document-requests/{id}/status', [DocumentRequestController::class, 'updateStatus'])->middleware($role('Registrar', 'Administrator'));
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    Route::middleware('oess.response')->group(function () use ($role, $queueStaff, $queueAdmin) {
        Route::get('/services', [QueueServiceController::class, 'index'])->middleware($role('Student', 'Registrar', 'Department Staff', 'Administrator'));
        Route::post('/services', [QueueServiceController::class, 'store'])->middleware($queueAdmin);
        Route::put('/services/{serviceId}', [QueueServiceController::class, 'update'])->middleware($queueAdmin);
        Route::delete('/services/{serviceId}', [QueueServiceController::class, 'destroy'])->middleware($queueAdmin);
        Route::get('/staff/services/{serviceId}/staff', [QueueServiceController::class, 'staffAssignments'])->middleware($queueAdmin);
        Route::put('/staff/services/{serviceId}/staff', [QueueServiceController::class, 'updateStaffAssignments'])->middleware($queueAdmin);
        Route::patch('/staff/services/{serviceId}/queue-pause', [QueueServiceController::class, 'setQueuePause'])->middleware($queueStaff);

        Route::get('/student/virtual-queue/services', [QueueController::class, 'studentServices'])->middleware($role('Student'));
        Route::get('/student/virtual-queue/tickets', [QueueController::class, 'studentTickets'])->middleware($role('Student'));
        Route::post('/student/virtual-queue/tickets', [QueueController::class, 'joinStudent'])->middleware($role('Student'));
        Route::patch('/student/virtual-queue/tickets/{queueEntryId}/cancel', [QueueController::class, 'cancelStudent'])->middleware($role('Student'));

        Route::get('/staff/queue', [QueueController::class, 'staffIndex'])->middleware($queueStaff);
        Route::get('/queue/{serviceId}', [QueueController::class, 'serviceQueue'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/call', [QueueController::class, 'call'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/start', [QueueController::class, 'start'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/recall', [QueueController::class, 'recall'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/complete', [QueueController::class, 'complete'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/no-show', [QueueController::class, 'noShow'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/cancel', [QueueController::class, 'cancel'])->middleware($queueStaff);
        Route::post('/staff/queue/{queueEntryId}/override', [QueueController::class, 'override'])->middleware($queueStaff);
    });
});
