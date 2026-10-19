<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class Oess
{
    public static function now(): string
    {
        return now('Asia/Manila')->format('Y-m-d h:i A');
    }

    // ENR-2026-001 style: highest existing number under the prefix + 1
    public static function nextId(string $model, string $prefix, int $pad = 3): string
    {
        $max = 0;
        foreach ($model::pluck('id') as $id) {
            if (str_starts_with($id, $prefix . '-') && preg_match('/(\d+)$/', $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $prefix . '-' . str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT);
    }

    // PAY-2026-4821 style
    public static function randomId(string $model, string $prefix, string $column = 'id'): string
    {
        do {
            $id = $prefix . '-' . random_int(1000, 9999);
        } while ($model::where($column, $id)->exists());
        return $id;
    }

    public static function audit(User $user, string $action, string $module, string $details): void
    {
        AuditLog::create([
            'id' => self::nextId(AuditLog::class, 'AUD'),
            'user' => $user->name . ' (' . $user->role . ')',
            'action' => $action,
            'dateTime' => self::now(),
            'module' => $module,
            'details' => $details,
        ]);
    }

    public static function notify(string $userId, string $title, string $message, string $module): void
    {
        Notification::create([
            'id' => self::randomId(Notification::class, 'NOTIF'),
            'userId' => $userId,
            'title' => $title,
            'message' => $message,
            'date' => self::now(),
            'isRead' => false,
            'relatedModule' => $module,
        ]);
    }

    public static function notifyRole(string $role, string $title, string $message, string $module): void
    {
        foreach (User::where('role', $role)->where('status', 'Active')->pluck('id') as $userId) {
            self::notify($userId, $title, $message, $module);
        }
    }

    public static function notifyStudent(string $studentNumber, string $title, string $message, string $module): void
    {
        $user = User::where('studentNumber', $studentNumber)->first();
        if ($user) {
            self::notify($user->id, $title, $message, $module);
        }
    }

    // Students only ever see their own records, staff can optionally filter with ?studentNumber=
    public static function scopeToStudent($query, User $user, ?string $requested)
    {
        if ($user->role === 'Student') {
            return $user->studentNumber
                ? $query->where('studentNumber', $user->studentNumber)
                : $query->whereRaw('1 = 0');
        }
        return $requested ? $query->where('studentNumber', $requested) : $query;
    }

    public static function paginateIfRequested($query, Request $request, array $searchableColumns = [])
    {
        if (! $request->hasAny(['page', 'per_page'])) {
            return $query->get();
        }

        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && $searchableColumns !== []) {
            $query->where(function ($builder) use ($search, $searchableColumns) {
                foreach ($searchableColumns as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        $filterField = $request->query('filter_field');
        $filterValue = $request->query('filter_value');
        if ($filterField !== null && $filterField !== '' && $filterValue !== null && $filterValue !== '') {
            abort_unless(in_array($filterField, $searchableColumns, true), 422, 'Invalid filter field.');
            $query->where($filterField, $filterValue);
        }

        return $query->paginate($perPage);
    }
}
