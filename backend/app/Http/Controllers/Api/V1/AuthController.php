<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\IdentityMatchingService;
use App\Services\MemberService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Autentikasi member berbasis token Sanctum (dipakai frontend Next.js). */
class AuthController extends Controller
{
    /** POST /auth/register — buat akun + member (student). */
    public function register(RegisterRequest $request, MemberService $members): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->create([
            'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => User::ROLE_STUDENT,
        ]);
        $members->createForUser($user, $data);
        $token = $user->createToken($request->string('device', 'web')->toString())->plainTextToken;
        AuditLog::record('auth.registered', $user, null, ['email' => $user->email], $user);
        $user->sendEmailVerificationNotification();

        return response()->json(['token' => $token, 'user' => new UserResource($user->load('member'))], 201);
    }

    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required'], 'device' => ['nullable', 'string', 'max:80']]);
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau sandi salah.']);
        }
        $token = $user->createToken($data['device'] ?? 'web')->plainTextToken;
        AuditLog::record('auth.login', $user, null, null, $user);

        return response()->json(['token' => $token, 'user' => new UserResource($user->load('member'))]);
    }

    /**
     * GET /auth/email/verify/{id}/{hash}?expires=&signature= — tanda tangan relatif (middleware signed:relative),
     * dipanggil halaman frontend /verifikasi-email. Tidak butuh login (tautan bisa dibuka di perangkat lain).
     */
    public function verifyEmail(Request $request, string $id, string $hash, IdentityMatchingService $identity): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw ValidationException::withMessages(['hash' => 'Tautan verifikasi tidak cocok dengan email akun ini.']);
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            AuditLog::record('auth.email_verified', $user, null, ['via' => 'link'], $user);
            $identity->afterEmailVerified($user);
        }

        return response()->json(['ok' => true, 'message' => 'Email terverifikasi.']);
    }

    /** POST /auth/email/resend — kirim ulang tautan verifikasi. */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['ok' => true, 'message' => 'Email sudah terverifikasi.']);
        }
        $user->sendEmailVerificationNotification();

        return response()->json(['ok' => true, 'message' => "Tautan verifikasi dikirim ke {$user->email}."]);
    }

    /** POST /auth/logout — cabut token yang dipakai. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /auth/forgot-password — kirim tautan reset (selalu 200 agar tidak membocorkan email terdaftar). */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return response()->json(['ok' => true, 'message' => 'Jika email terdaftar, tautan reset sandi telah dikirim.']);
    }

    /** POST /auth/reset-password */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
            AuditLog::record('auth.password_reset', $user, null, null, $user);
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['ok' => true]);
    }
}
