<?php


namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

class restaurentrestpassword extends Controller
{
    // Send Reset Link
    public function sendResetLink(Request $request)
    {
        $this->ensureRestaurantProvider();

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first('email')
            ], 422);
        }

        if (!User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is not registered.'
            ], 404);
        }

        // Laravel built-in reset email
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['success' => true, 'message' => 'Password reset link sent to email'])
            : response()->json(['success' => false, 'message' => 'Failed to send reset link'], 500);
    }
    // Update New Password
    public function resetPassword(Request $request)
    {
        $this->ensureRestaurantProvider();

        $request->validate([
            'email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'This email is not registered.'], 404);
        }

        $tokenRepository = Password::getRepository();

        if (!$tokenRepository->exists($user, $request->token)) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired token'], 400);
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        $tokenRepository->delete($user);
        event(new PasswordReset($user));

        return response()->json(['success' => true, 'message' => 'Password updated successfully']);
    }

    protected function ensureRestaurantProvider(): void
    {
        Config::set('auth.providers.users.model', User::class);
    }
}
