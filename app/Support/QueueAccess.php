<?php

namespace App\Support;

use App\Models\QueueService;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class QueueAccess
{
    private const SERVICE_STAFF_ROLES = ['Registrar', 'Department Staff'];

    public static function isAdministrator(User $user): bool
    {
        return $user->role === 'Administrator';
    }

    public static function accessibleServiceIds(User $user): array
    {
        if (self::isAdministrator($user)) {
            return QueueService::query()->pluck('id')->all();
        }
        if (! in_array($user->role, self::SERVICE_STAFF_ROLES, true)) {
            return [];
        }

        return DB::table('queue_service_staff')->where('userId', $user->id)->pluck('serviceId')->all();
    }

    public static function canAccessService(User $user, string $serviceId): bool
    {
        return self::isAdministrator($user) ||
            (in_array($user->role, self::SERVICE_STAFF_ROLES, true) &&
                DB::table('queue_service_staff')->where('userId', $user->id)->where('serviceId', $serviceId)->exists());
    }

    public static function assertServiceAccess(User $user, string $serviceId): void
    {
        abort_unless(self::canAccessService($user, $serviceId), 403, 'You are not assigned to this queue service.');
    }

    public static function services(User $user): Builder
    {
        $query = QueueService::query();
        if (! self::isAdministrator($user)) {
            $query->whereIn('id', self::accessibleServiceIds($user));
        }

        return $query;
    }

    public static function tickets(User $user): Builder
    {
        $query = QueueTicket::query();
        if (! self::isAdministrator($user)) {
            $query->whereIn('serviceId', self::accessibleServiceIds($user));
        }

        return $query;
    }
}
