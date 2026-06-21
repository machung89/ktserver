<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone,'.$request->user()->id],
            'email' => ['required', 'email', 'unique:users,email,'.$request->user()->id],
        ]);

        $request->user()->update($validated);

        return response()->json([
            'message' => 'Đã cập nhật thông tin',
            'user' => $request->user()->fresh(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không đúng.'],
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        return response()->json(['message' => 'Đã đổi mật khẩu thành công']);
    }
}
