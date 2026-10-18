<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    private function mine(Request $request)
    {
        $id = $request->user()->id;
        return Notification::where(function ($q) use ($id) {
            $q->whereNull('userId')->orWhere('userId', 'ALL')->orWhere('userId', $id);
        });
    }

    public function index(Request $request)
    {
        $result = Oess::paginateIfRequested(
            $this->mine($request)->orderByDesc('seq'),
            $request,
            ['title', 'message', 'relatedModule', 'date'],
            ['isRead'] // boolean column: filterable but not text-searchable
        );

        if ($result instanceof LengthAwarePaginator) {
            return response()->json($result->toArray() + [
                'unreadCount' => $this->mine($request)->where('isRead', false)->count(),
            ]);
        }
        return $result;
    }

    public function markRead(Request $request, string $id)
    {
        $this->mine($request)->where('id', $id)->update(['isRead' => true]);
        return response()->json(true);
    }

    public function markAllRead(Request $request)
    {
        $this->mine($request)->update(['isRead' => true]);
        return response()->json(true);
    }
}
