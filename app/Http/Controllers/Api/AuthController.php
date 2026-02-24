<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Models\admin_users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
// API Login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = admin_users::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

//        if ($user->role !== 'Super Administrator') {
//            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
//        }

        $allowedRoleIds = [1, 5]; // Super Administrator & Admin

        if (!in_array($user->role_id, $allowedRoleIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized - your role does not have access to this app'
            ], 403);
        }


// create token
        $token = $user->createToken('mobile_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

// Get logged in user info
    public function profile(Request $request)
    {
        return response()->json($request->user());
    }

// Logout user (invalidate token)
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
