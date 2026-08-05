<?php

use App\Models\Node;
use App\Models\Space;
use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->actingAs($this->alice);

    $this->aliceSpace = Space::create(['name' => 'Alice Space', 'slug' => 'gs-alice', 'user_id' => $this->alice->id]);
    $this->bobSpace = Space::create(['name' => 'Bob Space', 'slug' => 'gs-bob', 'user_id' => $this->bob->id]);

    Node::create(['space_id' => $this->aliceSpace->id, 'title' => 'Recipe for pancakes']);
    Node::create(['space_id' => $this->aliceSpace->id, 'title' => 'Unrelated node', 'description' => 'mentions pancakes here']);
    Node::create(['space_id' => $this->bobSpace->id, 'title' => 'Bobs pancake stand']);
});

test('search finds matches across the user\'s own spaces only, not unshared ones', function () {
    $results = $this->getJson('/api/search?q=pancake')->assertStatus(200)->json('results');

    expect(collect($results)->pluck('title'))
        ->toContain('Recipe for pancakes')
        ->toContain('Unrelated node')
        ->not->toContain('Bobs pancake stand');
});

test('search also covers spaces shared with the user', function () {
    $this->bobSpace->collaborators()->attach($this->alice->id, ['role' => 'viewer']);

    $results = $this->getJson('/api/search?q=pancake')->assertStatus(200)->json('results');

    expect(collect($results)->pluck('title'))->toContain('Bobs pancake stand');
});

test('a query shorter than 2 characters returns nothing without querying', function () {
    $this->getJson('/api/search?q=a')->assertStatus(200)->assertJson(['results' => []]);
});

test('results include the owning space name and slug for navigation', function () {
    $results = $this->getJson('/api/search?q=pancakes')->assertStatus(200)->json('results');

    $entry = collect($results)->firstWhere('title', 'Recipe for pancakes');
    expect($entry['space']['slug'])->toBe('gs-alice');
    expect($entry['space']['name'])->toBe('Alice Space');
});

test('root\'s global search reaches every space in the system, including admin\'s', function () {
    $root = User::where('is_root', true)->firstOrFail();
    $this->actingAs($root);

    $results = $this->getJson('/api/search?q=pancake')->assertStatus(200)->json('results');

    expect(collect($results)->pluck('title'))
        ->toContain('Recipe for pancakes')
        ->toContain('Bobs pancake stand');
});

test('percent and underscore in the query are treated literally, not as SQL wildcards', function () {
    Node::create(['space_id' => $this->aliceSpace->id, 'title' => 'Discount: 50% off_sale']);

    $results = $this->getJson('/api/search?q='.urlencode('50% off_sale'))
        ->assertStatus(200)
        ->json('results');

    expect(collect($results)->pluck('title'))->toContain('Discount: 50% off_sale');

    // A literal "%" shouldn't act as a wildcard and match everything.
    $wildcardAttempt = $this->getJson('/api/search?q='.urlencode('e%'))
        ->assertStatus(200)
        ->json('results');
    expect($wildcardAttempt)->toBe([]);
});
