<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\TeamProvisioner;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
});

test('two users who share a team can start a direct conversation', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    app(TeamProvisioner::class)->createTeam('Дизайн', '', [$alice->id, $bob->id]);

    $this->actingAs($alice);
    $response = $this->postJson("/api/messenger/direct/{$bob->id}");
    $response->assertStatus(201);

    $conversationId = $response->json('id');
    $this->getJson("/api/messenger/conversations/{$conversationId}/messages")->assertStatus(200);

    $this->actingAs($bob);
    $this->getJson("/api/messenger/conversations/{$conversationId}/messages")->assertStatus(200);
});

test('two users with no shared team cannot start a direct conversation', function () {
    $alice = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($alice);
    $this->postJson("/api/messenger/direct/{$stranger->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.user_id.0', 'not_a_teammate');
});

test('you cannot start a direct conversation with yourself', function () {
    $alice = User::factory()->create();
    $this->actingAs($alice);

    $this->postJson("/api/messenger/direct/{$alice->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.user_id.0', 'cannot_message_self');
});

test('root can DM anyone, and anyone can DM root, regardless of team membership', function () {
    $alice = User::factory()->create();

    $this->actingAs($this->root);
    $this->postJson("/api/messenger/direct/{$alice->id}")->assertStatus(201);

    $this->actingAs($alice);
    $bob = User::factory()->create();
    $this->postJson("/api/messenger/direct/{$this->root->id}")->assertStatus(201);
});

test('starting a direct conversation twice returns the same conversation, not a duplicate', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    app(TeamProvisioner::class)->createTeam('QA', '', [$alice->id, $bob->id]);

    $this->actingAs($alice);
    $first = $this->postJson("/api/messenger/direct/{$bob->id}")->json('id');
    $second = $this->postJson("/api/messenger/direct/{$bob->id}")->json('id');

    expect($second)->toBe($first);
    expect(Conversation::where('type', Conversation::TYPE_DIRECT)->count())->toBe(1);
});

test('losing the shared team does not retroactively lock an already-started direct conversation', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $team = app(TeamProvisioner::class)->createTeam('Temp Team', '', [$alice->id, $bob->id]);

    $this->actingAs($alice);
    $conversationId = $this->postJson("/api/messenger/direct/{$bob->id}")->json('id');
    $this->postJson("/api/messenger/conversations/{$conversationId}/messages", ['body' => 'before split']);

    app(TeamProvisioner::class)->removeMember($team, $bob);

    // Переписка остаётся доступной обоим, несмотря на то что общей команды
    // больше нет — прошлое не прячется задним числом.
    $this->getJson("/api/messenger/conversations/{$conversationId}/messages")->assertStatus(200);

    $this->actingAs($bob);
    $this->getJson("/api/messenger/conversations/{$conversationId}/messages")->assertStatus(200);
    $this->postJson("/api/messenger/conversations/{$conversationId}/messages", ['body' => 'still here'])
        ->assertStatus(201);
});

test('a stranger cannot read a direct conversation they are not part of', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    app(TeamProvisioner::class)->createTeam('Приватная', '', [$alice->id, $bob->id]);

    $this->actingAs($alice);
    $conversationId = $this->postJson("/api/messenger/direct/{$bob->id}")->json('id');

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->getJson("/api/messenger/conversations/{$conversationId}/messages")->assertStatus(403);
});

test('teammates endpoint lists people who share a team, plus root, but not unrelated users', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $stranger = User::factory()->create();
    app(TeamProvisioner::class)->createTeam('Команда', '', [$alice->id, $bob->id]);

    $this->actingAs($alice);
    $names = collect($this->getJson('/api/messenger/teammates')->json())->pluck('name');

    expect($names)->toContain($bob->name);
    expect($names)->toContain($this->root->name);
    expect($names)->not->toContain($stranger->name);
});
