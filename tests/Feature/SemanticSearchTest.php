<?php

use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Векторы контролируются вручную по подстроке в prompt — единичные
 * ("one-hot") векторы дают предсказуемую косинусную дистанцию: у
 * идентичных направлений distance≈0 (проходит порог 0.6 из
 * config('ollama.search_distance_threshold')), у ортогональных — ровно 1
 * (не проходит). Реальная similarity-математика считается в самом
 * Postgres (pgvector), не подменяется — фейкается только сеть до Ollama.
 */
function fakeOllamaFor(array $map): void
{
    $dimensions = 768;

    Http::fake(function ($request) use ($map, $dimensions) {
        $prompt = (string) ($request->data()['prompt'] ?? '');

        foreach ($map as $needle => $index) {
            if (str_contains($prompt, $needle)) {
                $vector = array_fill(0, $dimensions, 0.0);
                $vector[$index] = 1.0;

                return Http::response(['embedding' => $vector]);
            }
        }

        return Http::response(['embedding' => array_fill(0, $dimensions, 0.0001)]);
    });
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->space = Space::create(['name' => 'Semantic Space', 'slug' => 'semantic-space', 'user_id' => $this->owner->id]);
});

test('semantic search finds a node with no literal keyword overlap, ranked by meaning', function () {
    fakeOllamaFor([
        'Project Planning' => 0,
        'Cooking Recipes' => 1,
        'roadmap timeline' => 0, // запрос — направление совпадает с "Project Planning", не с "Cooking Recipes"
    ]);

    $this->actingAs($this->owner);
    $planning = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Project Planning Notes'])->json();
    $cooking = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Cooking Recipes'])->json();

    $response = $this->getJson('/api/search/semantic?q=roadmap timeline')->assertStatus(200)->json();
    $ids = collect($response['results'])->pluck('id');

    expect($ids)->toContain($planning['id']);
    expect($ids)->not->toContain($cooking['id']); // ортогональный вектор — за порогом 0.6
    expect(count($response['results']))->toBe(1);
});

test('semantic search only returns nodes from spaces the viewer can access', function () {
    fakeOllamaFor(['Project Planning' => 0, 'roadmap timeline' => 0]);

    $this->actingAs($this->owner);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Project Planning Notes']);

    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $response = $this->getJson('/api/search/semantic?q=roadmap timeline')->assertStatus(200)->json();

    expect($response['results'])->toBe([]);
});

test('semantic search degrades to an empty result, not an error, when Ollama is unreachable', function () {
    Http::fake(['*/api/embeddings' => Http::response(null, 500)]);

    $this->actingAs($this->owner);

    $this->getJson('/api/search/semantic?q=anything at all')
        ->assertStatus(200)
        ->assertJson(['results' => []]);
});

test('a query shorter than the minimum length returns an empty result without calling Ollama', function () {
    Http::fake();
    Http::preventStrayRequests();

    $this->actingAs($this->owner);

    $this->getJson('/api/search/semantic?q=a')
        ->assertStatus(200)
        ->assertJson(['results' => []]);
});
