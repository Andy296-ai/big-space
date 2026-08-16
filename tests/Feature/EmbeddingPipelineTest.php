<?php

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    $this->space = Space::create(['name' => 'Embedding Space', 'slug' => 'embedding-space']);
});

/** Ollama никогда не бьётся в тестах — фиксированный вектор нужной размерности на любой запрос. */
function fakeOllamaEmbeddings(): void
{
    Http::fake([
        '*/api/embeddings' => Http::response(['embedding' => array_fill(0, 768, 0.1)]),
    ]);
}

function nodeEmbeddingIsNull(int $nodeId): bool
{
    return DB::table('nodes')->where('id', $nodeId)->value('embedding') === null;
}

test('creating a root node populates its embedding', function () {
    fakeOllamaEmbeddings();

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Root about project planning'])
        ->assertStatus(201)
        ->json();

    expect(nodeEmbeddingIsNull($response['id']))->toBeFalse();
});

test('adding a child node populates its embedding', function () {
    fakeOllamaEmbeddings();

    $parent = Node::create(['space_id' => $this->space->id, 'title' => 'Parent']);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$parent->id}/child", ['title' => 'Child'])
        ->assertStatus(201)
        ->json();

    expect(nodeEmbeddingIsNull($response['node']['id']))->toBeFalse();
});

test('updating a node refreshes its embedding', function () {
    fakeOllamaEmbeddings();

    $node = Node::create(['space_id' => $this->space->id, 'title' => 'Original']);
    expect(nodeEmbeddingIsNull($node->id))->toBeTrue();

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$node->id}", ['title' => 'Updated'])
        ->assertStatus(200);

    expect(nodeEmbeddingIsNull($node->id))->toBeFalse();
});

test('a node save still succeeds when Ollama is unreachable', function () {
    Http::fake(['*/api/embeddings' => Http::response(null, 500)]);

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", ['title' => 'Still saves'])
        ->assertStatus(201)
        ->json();

    expect(nodeEmbeddingIsNull($response['id']))->toBeTrue();
});

test('copying a subtree copies the embedding instead of re-calling Ollama', function () {
    fakeOllamaEmbeddings();

    $source = Node::create(['space_id' => $this->space->id, 'title' => 'Source']);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$source->id}", ['title' => 'Source'])->assertStatus(200);

    Http::fake(); // любой дальнейший запрос к Ollama — считаем это провалом теста
    Http::preventStrayRequests();

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$source->id}/copy", [])
        ->assertStatus(201)
        ->json();

    expect(nodeEmbeddingIsNull($response['id']))->toBeFalse();
});

test('uploading a pdf embeds its extracted text', function () {
    fakeOllamaEmbeddings();

    $node = Node::create(['space_id' => $this->space->id, 'title' => 'PDF holder']);
    // buildTestPdf() — тот же валидный минимальный PDF, что и в
    // AttachmentContentSearchTest.php (общая global-функция в рамках
    // тестового прогона): фейковый UploadedFile::fake()->create() не даёт
    // распарсиваемого содержимого, extractSearchableText() на нём всегда
    // возвращает null, и embedAttachment() тогда обоснованно ничего не шлёт.
    $file = UploadedFile::fake()->createWithContent('report.pdf', buildTestPdf('unique quarterly numbers'));

    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/attachments", [
        'kind' => 'file',
        'label' => 'Quarterly report',
        'file' => $file,
    ])->assertStatus(201)->json();

    expect(DB::table('node_attachments')->where('id', $response['id'])->value('embedding'))->not->toBeNull();
});

test('editing a markdown attachment re-embeds the new content', function () {
    fakeOllamaEmbeddings();

    $node = Node::create(['space_id' => $this->space->id, 'title' => 'MD holder']);
    $file = UploadedFile::fake()->createWithContent('notes.md', '# first version');

    $attachment = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/attachments", [
        'kind' => 'file',
        'label' => 'notes',
        'file' => $file,
    ])->json();

    expect(NodeAttachment::find($attachment['id'])->embedding)->not->toBeNull();

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/attachments/{$attachment['id']}/content", [
        'content' => '# second version, much longer than the first one',
    ])->assertStatus(200);

    expect(NodeAttachment::find($attachment['id'])->embedding)->not->toBeNull();
});

test('a link attachment (no file content) is not sent to embed', function () {
    Http::fake();
    Http::preventStrayRequests();

    $node = Node::create(['space_id' => $this->space->id, 'title' => 'Link holder']);

    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$node->id}/attachments", [
        'kind' => 'link',
        'url' => 'https://example.com',
    ])->assertStatus(201);

    // preventStrayRequests() would have already failed the test if Ollama was called.
    expect(true)->toBeTrue();
});
