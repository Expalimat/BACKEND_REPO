<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Oess;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return Oess::paginateIfRequested(AuditLog::orderByDesc('seq'), $request, [
            'user', 'action', 'module', 'details', 'dateTime',
        ]);
    }
}
