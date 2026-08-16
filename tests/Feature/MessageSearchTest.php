<?php

use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->author = User::factory()->create();
    $this->global->participants()->attach($this->author->id);
});

test('search finds a matching text message by substring', function () {
    $this->actingAs($this->author);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'the quarterly report is ready']);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'unrelated message']);

    $results = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/search?q=quarterly")
        ->assertStatus(200)
        ->json();

    expect($results)->toHaveCount(1);
    expect($results[0]['body'])->toBe('the quarterly report is ready');
});

test('search excludes deleted messages even for root', function () {
    $this->actingAs($this->author);
    $created = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'secret keyword here'])->json();
    $this->deleteJson("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}");

    $this->actingAs($this->root);
    $results = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/search?q=secret")
        ->assertStatus(200)
        ->json();

    expect($results)->toHaveCount(0);
});

test('search query shorter than 2 characters returns an empty result without erroring', function () {
    $this->actingAs($this->author);

    $results = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages/search?q=a")
        ->assertStatus(200)
        ->json();

    expect($results)->toBe([]);
});

test('a percent sign in the query is treated literally, not as a SQL wildcard', function () {
    $this->actingAs($this->author);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'discount is 50% off']);
    $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => 'no wildcard match here']);

    $results = $this->getJson('/api/messenger/conversations/'.$this->global->id.'/messages/search?q='.urlencode('50%'))
        ->assertStatus(200)
        ->json();

    expect($results)->toHaveCount(1);
    expect($results[0]['body'])->toBe('discount is 50% off');
});

test('around_id fetches messages before and after a target id in one page', function () {
    $this->actingAs($this->author);
    $ids = [];

    for ($i = 0; $i < 10; $i++) {
        $ids[] = $this->postJson("/api/messenger/conversations/{$this->global->id}/messages", ['body' => "msg {$i}"])->json()['id'];
    }

    $targetId = $ids[5];

    $page = $this->getJson("/api/messenger/conversations/{$this->global->id}/messages?around_id={$targetId}&limit=6")
        ->assertStatus(200)
        ->json();

    $returnedIds = array_column($page, 'id');
    expect($returnedIds)->toContain($targetId);
    // Отсортировано по возрастанию id (хронологический порядок), как и обычная страница.
    expect($returnedIds)->toBe(collect($returnedIds)->sort()->values()->all());
});
