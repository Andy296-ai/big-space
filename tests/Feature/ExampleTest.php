<?php

use App\Models\User;

test('returns a successful response', function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());

    $response = $this->get(route('home'));

    $response->assertOk();
});
