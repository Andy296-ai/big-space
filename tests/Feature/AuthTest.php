<?php

use App\Models\User;

test('the root account is created by migration', function () {
    $this->assertDatabaseHas('users', ['name' => config('auth.root.username')]);
});

test('guests are redirected from the app to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('guests cannot reach the api', function () {
    // Роуты /api отдают JSON, поэтому здесь ожидаем 401, а не редирект.
    $this->getJson('/api/spaces')->assertStatus(401);
});

test('the login page renders for guests', function () {
    $this->get('/login')->assertStatus(200);
});

test('root signs in with the configured credentials', function () {
    $response = $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();
});

test('a wrong password is rejected', function () {
    $response = $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => 'definitely-not-the-password',
    ]);

    $response->assertSessionHasErrors(['username' => 'invalid_credentials']);
    $this->assertGuest();
});

test('repeated failures are rate limited', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'username' => config('auth.root.username'),
            'password' => 'wrong',
        ]);
    }

    // Шестая попытка отсекается лимитером, даже если пароль верный.
    $response = $this->post('/login', [
        'username' => config('auth.root.username'),
        'password' => config('auth.root.password'),
    ]);

    $response->assertSessionHasErrors(['username' => 'too_many_attempts']);
    $this->assertGuest();
});

test('signing out ends the session', function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});
