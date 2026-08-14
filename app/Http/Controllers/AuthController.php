<?php

namespace App\Http\Controllers;

use App\Mail\LoginVerificationCodeMail;
use App\Models\LoginVerificationCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /** Сколько раз подряд можно ошибиться с кодом, прежде чем ждать его истечения. */
    private const MAX_CODE_ATTEMPTS = 8;

    public function create(Request $request): Response
    {
        $user = self::pendingUser($request);

        if ($user !== null) {
            return Inertia::render('Login', [
                'step' => 'code',
                'maskedEmail' => self::maskEmail($user->email),
            ]);
        }

        if ($request->session()->has('2fa_user_id')) {
            // Пользователь исчез (удалён между шагами) — не застреваем на шаге 2.
            $request->session()->forget(['2fa_user_id', '2fa_remember']);
        }

        return Inertia::render('Login', ['step' => 'credentials']);
    }

    /** Шаг 1: логин+пароль. Полноценный вход откладывается до подтверждения кода. */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Учётка одна на всё приложение, поэтому подбор пароля надо ограничить.
        $throttleKey = Str::lower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'username' => 'too_many_attempts',
            ]);
        }

        // Логином служит колонка name — отдельного username в схеме нет,
        // а учётная запись в приложении одна.
        // Auth::validate(), а не attempt(): проверяет пароль, но НЕ трогает
        // сессию — полноценный вход происходит только после verifyCode().
        $valid = Auth::validate([
            'name' => $credentials['username'],
            'password' => $credentials['password'],
        ]);

        if (! $valid) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            // Код, а не текст: страница входа переводит его на текущий язык.
            throw ValidationException::withMessages([
                'username' => 'invalid_credentials',
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::getLastAttempted();

        // Ротация ID сессии сразу, как только пароль подтверждён — до
        // окончательного входа, а не только после него.
        $request->session()->regenerate();

        $code = LoginVerificationCode::issue($user, $request->ip());
        Mail::to($user->email)->send(new LoginVerificationCodeMail($code, (int) config('two_factor.code_ttl_minutes')));

        $request->session()->put('2fa_user_id', $user->id);
        $request->session()->put('2fa_remember', $request->boolean('remember'));

        return redirect()->route('login');
    }

    /** Шаг 2: код из письма. Целевой пользователь берётся ИСКЛЮЧИТЕЛЬНО из сессии — не из запроса. */
    public function verifyCode(Request $request): RedirectResponse
    {
        $user = self::pendingUser($request);

        if ($user === null) {
            throw ValidationException::withMessages(['code' => 'code_expired']);
        }

        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $throttleKey = "2fa-verify:{$user->id}";

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_CODE_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'too_many_attempts']);
        }

        if (! LoginVerificationCode::verify($user, $validated['code'])) {
            RateLimiter::hit($throttleKey, (int) config('two_factor.code_ttl_minutes') * 60);

            throw ValidationException::withMessages(['code' => 'code_invalid']);
        }

        RateLimiter::clear($throttleKey);

        $remember = $request->session()->get('2fa_remember', false);
        $request->session()->forget(['2fa_user_id', '2fa_remember']);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /** Повторная отправка кода — тот же получатель, что и на шаге 1 (из сессии). */
    public function resendCode(Request $request): RedirectResponse
    {
        $user = self::pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $throttleKey = "2fa-resend:{$user->id}";

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            throw ValidationException::withMessages(['code' => 'too_many_attempts']);
        }

        RateLimiter::hit($throttleKey, 30);

        $code = LoginVerificationCode::issue($user, $request->ip());
        Mail::to($user->email)->send(new LoginVerificationCodeMail($code, (int) config('two_factor.code_ttl_minutes')));

        return redirect()->route('login');
    }

    /** «Назад к входу» — реальный round-trip, иначе GET /login после обновления страницы снова покажет шаг 2. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['2fa_user_id', '2fa_remember']);

        return redirect()->route('login');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /** Пользователь, ожидающий ввода кода — строго из сессии, никогда из запроса. */
    private static function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get('2fa_user_id');

        return $userId === null ? null : User::find((int) $userId);
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('*', max(mb_strlen($local) - 2, 2)).'@'.$domain;
    }
}
