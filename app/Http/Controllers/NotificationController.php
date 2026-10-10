<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

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
        return $this->mine($request)->orderByDesc('seq')->get();
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
