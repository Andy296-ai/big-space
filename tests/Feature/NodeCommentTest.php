<?php

use App\Events\CommentPosted;
use App\Models\Node;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->actingAs($this->owner);
    $this->space = Space::create(['name' => 'Comments', 'slug' => 'comments-space', 'user_id' => $this->owner->id]);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Discuss me']);
    $this->space->collaborators()->attach($this->viewer->id, ['role' => 'viewer']);
});

test('a viewer can post and read a comment, not just editors', function () {
    $this->actingAs($this->viewer);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => 'Looks good to me',
    ])->assertStatus(201)->assertJsonPath('body', 'Looks good to me');

    $list = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments")
        ->assertStatus(200)
        ->json();

    expect($list)->toHaveCount(1);
    expect($list[0]['user']['id'])->toBe($this->viewer->id);
});

test('a stranger with no access cannot read or post comments', function () {
    $this->actingAs($this->stranger);

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments")->assertStatus(403);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", ['body' => 'nope'])
        ->assertStatus(403);
});

test('posting a comment broadcasts a signal scoped to the space and node', function () {
    Event::fake([CommentPosted::class]);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", ['body' => 'Hi']);

    Event::assertDispatched(
        CommentPosted::class,
        fn (CommentPosted $e) => $e->spaceId === $this->space->id && $e->nodeId === $this->node->id,
    );
});

test('the author can delete their own comment, and so can the space owner', function () {
    $this->actingAs($this->viewer);
    $comment = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => 'delete me',
    ])->json();

    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments/{$comment['id']}")
        ->assertStatus(200);
    $this->assertDatabaseMissing('node_comments', ['id' => $comment['id']]);

    $second = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => 'owner will delete this',
    ])->json();

    $this->actingAs($this->owner);
    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments/{$second['id']}")
        ->assertStatus(200);
});

test('another viewer cannot delete someone else\'s comment', function () {
    $this->actingAs($this->viewer);
    $comment = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => 'mine',
    ])->json();

    $otherViewer = User::factory()->create();
    $this->space->collaborators()->attach($otherViewer->id, ['role' => 'viewer']);
    $this->actingAs($otherViewer);

    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments/{$comment['id']}")
        ->assertStatus(403);
});

test('the comment body is required and capped in length', function () {
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", ['body' => ''])
        ->assertStatus(422);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => str_repeat('a', 2001),
    ])->assertStatus(422);
});

test('a deleted comment author still shows up as historical content, not a broken row', function () {
    $comment = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments", [
        'body' => 'survivor',
    ])->json();

    $this->owner->delete();

    $list = $this->actingAs(User::where('is_root', true)->firstOrFail())
        ->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/comments")
        ->assertStatus(200)
        ->json();

    $entry = collect($list)->firstWhere('id', $comment['id']);
    expect($entry['body'])->toBe('survivor');
    expect($entry['user'])->toBeNull();
});
