<?php

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    $this->space = Space::create(['name' => 'Logo Space', 'slug' => 'logo-space']);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Node']);
});

test('uploading a logo actually persists it — logo_path was missing from Node::$fillable, so update() silently no-opped', function () {
    $file = UploadedFile::fake()->image('logo.png', 64, 64);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $file])
        ->assertStatus(200)
        ->json();

    expect($response['logo_url'])->not->toBeNull();
    $this->assertDatabaseHas('nodes', ['id' => $this->node->id]);
    expect($this->node->fresh()->logo_path)->not->toBeNull();

    $this->get($response['logo_url'])->assertStatus(200);
});

test('an svg logo is accepted, not rejected by the image validation rule', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    $file = UploadedFile::fake()->createWithContent('logo.svg', $svg);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $file])
        ->assertStatus(200)
        ->json();

    expect($response['logo_url'])->not->toBeNull();
});

test('uploading a new logo replaces and deletes the previous file', function () {
    $first = UploadedFile::fake()->image('first.png', 64, 64);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $first]);
    $firstPath = $this->node->fresh()->logo_path;

    $second = UploadedFile::fake()->image('second.png', 64, 64);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $second]);

    expect(Storage::disk(NodeAttachment::DISK)->exists($firstPath))->toBeFalse();
    expect($this->node->fresh()->logo_path)->not->toBe($firstPath);
});

test('logo_url changes after replacing the logo, so browser/frontend caches keyed by url bust automatically', function () {
    $first = UploadedFile::fake()->image('first.png', 64, 64);
    $firstResponse = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $first])->json();

    $second = UploadedFile::fake()->image('second.png', 64, 64);
    $secondResponse = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $second])->json();

    expect($secondResponse['logo_url'])->not->toBe($firstResponse['logo_url']);
});

test('a non-image file is rejected', function () {
    $file = UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain');

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $file])
        ->assertStatus(422);
});

test('a user without edit access cannot upload a logo', function () {
    $viewer = User::factory()->create();
    $this->space->collaborators()->attach($viewer->id, ['role' => 'viewer']);

    $this->actingAs($viewer);
    $file = UploadedFile::fake()->image('logo.png', 64, 64);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/logo", ['logo' => $file])
        ->assertStatus(403);
});
