<?php

use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamProvisioner;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->actingAs($this->root);
});

test('root creates a team, which gets its own group conversation with the initial members attached', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $response = $this->postJson('/api/admin/teams', [
        'name' => 'Дизайн',
        'description' => 'Команда дизайна',
        'member_ids' => [$alice->id, $bob->id],
    ]);

    $response->assertStatus(201);
    $teamId = $response->json('id');

    $team = Team::findOrFail($teamId);
    $conversation = Conversation::where('type', Conversation::TYPE_TEAM)->where('team_id', $team->id)->firstOrFail();

    expect($team->users()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$alice->id, $bob->id])->sort()->values()->all());
    expect($conversation->participants()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$alice->id, $bob->id])->sort()->values()->all());

    $this->actingAs($alice);
    $this->getJson("/api/messenger/conversations/{$conversation->id}/messages")->assertStatus(200);
});

test('a non-root user cannot manage teams', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->postJson('/api/admin/teams', ['name' => 'Sneaky'])->assertStatus(403);
    $this->getJson('/api/admin/teams')->assertStatus(403);
});

test('a stranger who was never added to the team cannot read its conversation', function () {
    $team = app(TeamProvisioner::class)->createTeam('Разработка', '', []);
    $conversation = $team->conversation;

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->getJson("/api/messenger/conversations/{$conversation->id}/messages")->assertStatus(403);
});

test('adding a member grants conversation access, removing revokes it immediately', function () {
    $team = app(TeamProvisioner::class)->createTeam('QA', '', []);
    $conversation = $team->conversation;
    $carol = User::factory()->create();

    $this->postJson("/api/admin/teams/{$team->id}/members", ['identifier' => $carol->name])
        ->assertStatus(201);

    $this->actingAs($carol);
    $this->postJson("/api/messenger/conversations/{$conversation->id}/messages", ['body' => 'hi team'])
        ->assertStatus(201);

    $this->actingAs($this->root);
    $this->deleteJson("/api/admin/teams/{$team->id}/members/{$carol->id}")->assertStatus(200);

    $this->actingAs($carol);
    $this->getJson("/api/messenger/conversations/{$conversation->id}/messages")->assertStatus(403);
});

test('deleting a team removes its conversation and messages', function () {
    $team = app(TeamProvisioner::class)->createTeam('Temp', '', []);
    $conversation = $team->conversation;
    $conversationId = $conversation->id;

    $conversation->participants()->attach($this->root->id);
    $this->postJson("/api/messenger/conversations/{$conversationId}/messages", ['body' => 'about to vanish']);

    $this->deleteJson("/api/admin/teams/{$team->id}")->assertStatus(200);

    $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    $this->assertDatabaseMissing('conversations', ['id' => $conversationId]);
    $this->assertDatabaseMissing('messages', ['conversation_id' => $conversationId]);
});

test('a team name must be unique', function () {
    app(TeamProvisioner::class)->createTeam('Уникальная', '', []);

    $this->postJson('/api/admin/teams', ['name' => 'Уникальная'])->assertStatus(422);
});
