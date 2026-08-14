<?php

use App\Mail\LoginVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Код хранится в БД только как хэш — доставать его для теста надо из письма, не из таблицы. */
function capturedLoginCode(): string
{
    $code = null;

    Mail::assertSent(LoginVerificationCodeMail::class, function (LoginVerificationCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

test('the full flow signs in after a valid code', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ])->assertRedirect('/login');
    $this->assertGuest();

    $response = $this->post('/login/verify-code', ['code' => capturedLoginCode()]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();
});

test('root\'s code is sent to the configured root email, not the placeholder', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    Mail::assertSent(
        LoginVerificationCodeMail::class,
        fn ($mail) => $mail->hasTo(config('auth.root.email')),
    );
});

test('a wrong code is rejected and does not sign in', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    $response = $this->post('/login/verify-code', ['code' => '000000']);

    $response->assertSessionHasErrors(['code' => 'code_invalid']);
    $this->assertGuest();
});

test('an expired code is rejected', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    $code = capturedLoginCode();
    $this->travel((int) config('two_factor.code_ttl_minutes') + 1)->minutes();

    $response = $this->post('/login/verify-code', ['code' => $code]);

    $response->assertSessionHasErrors(['code' => 'code_invalid']);
    $this->assertGuest();
});

test('verify-code refuses to work without a pending login from step one', function () {
    $response = $this->post('/login/verify-code', ['code' => '123456']);

    $response->assertSessionHasErrors(['code']);
    $this->assertGuest();
});

test('resending invalidates the previous code', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);
    $firstCode = capturedLoginCode();

    $this->post('/login/resend-code');

    // Первый код больше не должен работать.
    $this->post('/login/verify-code', ['code' => $firstCode])
        ->assertSessionHasErrors(['code' => 'code_invalid']);
    $this->assertGuest();
});

test('repeated wrong codes are rate limited', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    foreach (range(1, 8) as $ignored) {
        $this->post('/login/verify-code', ['code' => '000000']);
    }

    $response = $this->post('/login/verify-code', ['code' => '000000']);

    $response->assertSessionHasErrors(['code' => 'too_many_attempts']);
});

test('cancel clears the pending login and returns to the credentials step', function () {
    Mail::fake();

    $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    $this->post('/login/cancel')->assertRedirect('/login');

    $page = $this->get('/login');
    $page->assertInertia(fn ($assert) => $assert->where('step', 'credentials'));
});

test('a non-root user\'s code goes to their own email', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'someone@example.com',
        'password' => 'a very secret password',
    ]);

    $this->post('/login', [
        'username' => $user->name,
        'password' => 'a very secret password',
    ]);

    Mail::assertSent(
        LoginVerificationCodeMail::class,
        fn ($mail) => $mail->hasTo('someone@example.com'),
    );
});
