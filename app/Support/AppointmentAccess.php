<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AppointmentAccess
{
    private const SERVICE_STAFF_ROLES = ['Registrar', 'Department Staff'];

    public static function isAdministrator(User $user): bool
    {
        return $user->role === 'Administrator';
    }

    public static function accessibleServiceIds(User $user): array
    {
        if (self::isAdministrator($user)) {
            return AppointmentService::query()->pluck('id')->all();
        }
        if (! in_array($user->role, self::SERVICE_STAFF_ROLES, true)) {
            return [];
        }

        return DB::table('appointment_service_staff')
            ->where('userId', $user->id)
            ->pluck('serviceId')
            ->all();
    }

    public static function canAccessService(User $user, string $serviceId): bool
    {
        return self::isAdministrator($user) ||
            (in_array($user->role, self::SERVICE_STAFF_ROLES, true) &&
                DB::table('appointment_service_staff')
                    ->where('userId', $user->id)
                    ->where('serviceId', $serviceId)
                    ->exists());
    }

    public static function assertServiceAccess(User $user, string $serviceId): void
    {
        if (! self::canAccessService($user, $serviceId)) {
            abort(403, 'You are not assigned to this appointment service.');
        }
    }

    public static function services(User $user): Builder
    {
        $query = AppointmentService::query();
        if (! self::isAdministrator($user)) {
            $query->whereIn('id', self::accessibleServiceIds($user));
        }

        return $query;
    }

    public static function appointments(User $user): Builder
    {
        $query = Appointment::query();
        if ($user->role === 'Student') {
            return $query->where('studentNumber', $user->studentNumber ?? '');
        }
        if (! self::isAdministrator($user)) {
            $query->whereIn('serviceId', self::accessibleServiceIds($user));
        }

        return $query;
    }

    public static function slots(User $user): Builder
    {
        $query = AppointmentSlot::query();
        if (! self::isAdministrator($user)) {
            $query->whereIn('serviceId', self::accessibleServiceIds($user));
        }

        return $query;
    }

    public static function queueEntries(User $user): Builder
    {
        $query = AppointmentQueueEntry::query();
        if (! self::isAdministrator($user)) {
            $query->whereIn('serviceId', self::accessibleServiceIds($user));
        }

        return $query;
    }
}
