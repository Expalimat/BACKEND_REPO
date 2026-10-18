<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['Student', 'Registrar', 'Cashier', 'Department Staff', 'Administrator'];

    public function index(Request $request)
    {
        return Oess::paginateIfRequested(User::orderBy('seq'), $request, [
            'username', 'name', 'email', 'role', 'studentNumber', 'program', 'yearLevel', 'department', 'status',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'role' => ['required', Rule::in(self::ROLES)],
            'email' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'studentNumber' => 'nullable|string|max:50',
            'program' => 'nullable|string|max:255',
            'yearLevel' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:255',
        ]);

        $username = strtolower(trim($data['username']));
        if (User::where('username', $username)->exists()) {
            return response()->json(['message' => 'That username is already taken.'], 422);
        }

        $initials = collect(preg_split('/\s+/', trim($data['name'])))
            ->map(fn ($n) => mb_substr($n, 0, 1))->implode('');

        $user = User::create([
            'id' => Oess::nextId(User::class, 'USR'),
            'username' => $username,
            'password' => $data['password'] ?? 'password123',
            'role' => $data['role'],
            'name' => $data['name'],
            'email' => $data['email'] ?? '',
            'studentNumber' => $data['studentNumber'] ?? '',
            'program' => $data['program'] ?? '',
            'yearLevel' => $data['yearLevel'] ?? '',
            'department' => $data['department'] ?? '',
            'status' => 'Active',
            'avatar' => strtoupper(mb_substr($initials, 0, 2)),
        ]);

        Oess::audit($request->user(), 'Created User Account', 'Users', "Created {$user->role} user: {$user->username}");
        return response()->json($user->fresh(), 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'username' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'email' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'studentNumber' => 'nullable|string|max:50',
            'program' => 'nullable|string|max:255',
            'yearLevel' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:255',
            'status' => ['sometimes', Rule::in(['Active', 'Inactive'])],
        ]);

        if (isset($data['username'])) {
            $data['username'] = strtolower(trim($data['username']));
            if (User::where('username', $data['username'])->where('id', '!=', $id)->exists()) {
                return response()->json(['message' => 'That username is already taken.'], 422);
            }
        }
        // blank password field on the edit form means "keep the current one"
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        Oess::audit($request->user(), 'Updated User Account', 'Users', "Updated account for {$user->name} ({$user->username})");
        return $user->fresh();
    }

    public function toggleStatus(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->status = $user->status === 'Active' ? 'Inactive' : 'Active';
        $user->save();
        if ($user->status === 'Inactive') {
            $user->tokens()->delete(); // kick them out right away
        }

        Oess::audit(
            $request->user(),
            ($user->status === 'Active' ? 'Activated' : 'Deactivated') . ' User Account',
            'Users',
            "Changed status of {$user->username} to {$user->status}"
        );
        return $user;
    }
}
