<?php

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    Storage::fake(NodeAttachment::DISK);

    $this->space = Space::create(['name' => 'Files', 'slug' => 'files']);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Узел']);
});

test('a file is uploaded, stored privately and downloaded back', function () {
    $response = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('смета.pdf', 12)],
    );

    $response->assertStatus(201);
    expect($response->json('kind'))->toBe('file');
    expect($response->json('badge'))->toBe('PDF');
    expect($response->json('stored'))->toBeTrue();
    // Путь в хранилище наружу не отдаём.
    expect($response->json('path'))->toBeNull();

    $attachment = NodeAttachment::findOrFail($response->json('id'));
    Storage::disk(NodeAttachment::DISK)->assertExists($attachment->path);

    $download = $this->get(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$attachment->id}/download"
    )->assertStatus(200);

    // По RFC 6266 в filename идёт ASCII-транслитерация, а кириллица — в filename*,
    // и браузер показывает именно её.
    $disposition = $download->headers->get('content-disposition');
    expect($disposition)->toContain('attachment');
    expect($disposition)->toContain("filename*=utf-8''");
    expect(rawurldecode($disposition))->toContain('смета.pdf');
});

test('a link is stored without touching the disk', function () {
    $response = $this->postJson(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'link', 'label' => 'Сайт', 'url' => 'https://example.com/page'],
    );

    $response->assertStatus(201);
    expect($response->json('stored'))->toBeFalse();
    expect($response->json('url'))->toBe('https://example.com/page');

    // Скачивать у ссылки нечего.
    $this->get("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$response->json('id')}/download")
        ->assertStatus(404);
});

test('deleting an attachment removes the stored file', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('a.zip', 4)],
    )->json('id');

    $path = NodeAttachment::findOrFail($id)->path;
    Storage::disk(NodeAttachment::DISK)->assertExists($path);

    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}")
        ->assertStatus(200);

    Storage::disk(NodeAttachment::DISK)->assertMissing($path);
    expect(NodeAttachment::find($id))->toBeNull();
});

test('attachments of another node are not reachable', function () {
    $other = Node::create(['space_id' => $this->space->id, 'title' => 'Чужой']);
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('a.pdf', 4)],
    )->json('id');

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments/{$id}/download")
        ->assertStatus(404);
    $this->deleteJson("/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments/{$id}")
        ->assertStatus(404);

    // Узел из другого пространства тоже не подходит.
    $foreign = Space::create(['name' => 'Foreign', 'slug' => 'foreign-files']);
    $this->getJson("/api/spaces/{$foreign->id}/nodes/{$this->node->id}/attachments/{$id}/download")
        ->assertStatus(404);
});

test('an upload without a file or a link is rejected', function () {
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments", ['kind' => 'file'])
        ->assertStatus(422);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments", ['kind' => 'link'])
        ->assertStatus(422);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments", [
        'kind' => 'link', 'url' => 'не-ссылка',
    ])->assertStatus(422);
});

test('guests cannot upload or download', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('a.pdf', 4)],
    )->json('id');

    auth()->logout();

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/download")
        ->assertStatus(401);
    $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments", ['kind' => 'link', 'url' => 'https://a.b'])
        ->assertStatus(401);
});

test('a previewable file is served inline with the right content type', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('план.pdf', 12)],
    )->json('id');

    expect(NodeAttachment::findOrFail($id)->previewable)->toBeTrue();

    $preview = $this->get("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/preview")
        ->assertStatus(200);

    expect($preview->headers->get('content-type'))->toContain('application/pdf');
    expect($preview->headers->get('content-disposition'))->toContain('inline');
});

test('preview supports range requests, needed for video seeking', function () {
    // UploadedFile::fake()->create() кладёт на диск пустой файл (размер только
    // «заявленный», для проверки валидации) — для Range нужен реальный контент.
    $content = str_repeat('0123456789', 20);
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('клип.mp4', $content)],
    )->json('id');

    $response = $this->withHeaders(['Range' => 'bytes=0-10'])
        ->get("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/preview");

    $response->assertStatus(206);
    expect($response->headers->get('accept-ranges'))->toBe('bytes');
    expect($response->headers->get('content-range'))->toStartWith('bytes 0-10/');
});

test('video attachments are previewable but not editable', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('клип.mp4', 4)],
    )->json('id');

    $attachment = NodeAttachment::findOrFail($id);
    expect($attachment->previewable)->toBeTrue();
    expect($attachment->editable)->toBeFalse();

    $preview = $this->get("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/preview")
        ->assertStatus(200);
    expect($preview->headers->get('content-type'))->toContain('video/mp4');

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content")
        ->assertStatus(404);
});

test('a non-previewable file cannot be previewed', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('архив.zip', 4)],
    )->json('id');

    expect(NodeAttachment::findOrFail($id)->previewable)->toBeFalse();

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/preview")
        ->assertStatus(404);
});

test('markdown content can be read and rewritten', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('заметка.md', '# Привет')],
    )->json('id');

    expect(NodeAttachment::findOrFail($id)->editable)->toBeTrue();

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content")
        ->assertStatus(200)
        ->assertJson(['content' => '# Привет']);

    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content", [
        'content' => '# Обновлено',
    ])->assertStatus(200);

    $updated = $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content")
        ->assertStatus(200);
    expect($updated->json('content'))->toBe('# Обновлено');
});

test('non-editable files reject content reads and writes', function () {
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->create('фото.jpg', 4)],
    )->json('id');

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content")
        ->assertStatus(404);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content", ['content' => 'x'])
        ->assertStatus(404);
});

test('a link has no preview or editable content', function () {
    $id = $this->postJson(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'link', 'url' => 'https://example.com/note.md'],
    )->json('id');

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/preview")
        ->assertStatus(404);
    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments/{$id}/content")
        ->assertStatus(404);
});

test('preview and content of another node are not reachable', function () {
    $other = Node::create(['space_id' => $this->space->id, 'title' => 'Чужой']);
    $id = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('заметка.md', 'секрет')],
    )->json('id');

    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments/{$id}/preview")
        ->assertStatus(404);
    $this->getJson("/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments/{$id}/content")
        ->assertStatus(404);
    $this->putJson("/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments/{$id}/content", ['content' => 'x'])
        ->assertStatus(404);
});

test('attachment content search finds the node by a phrase inside its markdown', function () {
    $other = Node::create(['space_id' => $this->space->id, 'title' => 'Другой узел']);

    $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('заметка.md', 'секретная фраза внутри')],
    )->assertStatus(201);

    $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$other->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('другая.md', 'ничего общего')],
    )->assertStatus(201);

    $response = $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=".urlencode('секретная фраза'))
        ->assertStatus(200);

    expect($response->json('node_ids'))->toBe([$this->node->id]);
});

test('attachment search ignores non-text formats and short queries', function () {
    $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('фото.jpg', 'секретная фраза внутри')],
    )->assertStatus(201);

    $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=".urlencode('секретная'))
        ->assertStatus(200)
        ->assertJson(['node_ids' => []]);

    $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=a")
        ->assertStatus(200)
        ->assertJson(['node_ids' => []]);
});

test('attachment search is scoped to the space', function () {
    $foreign = Space::create(['name' => 'Foreign', 'slug' => 'foreign-search']);
    $foreignNode = Node::create(['space_id' => $foreign->id, 'title' => 'Чужой']);

    $this->post(
        "/api/spaces/{$foreign->id}/nodes/{$foreignNode->id}/attachments",
        ['kind' => 'file', 'file' => UploadedFile::fake()->createWithContent('заметка.md', 'уникальная строка')],
    )->assertStatus(201);

    $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=".urlencode('уникальная строка'))
        ->assertStatus(200)
        ->assertJson(['node_ids' => []]);
});

test('new nodes accept a map point', function () {
    $response = $this->postJson("/api/spaces/{$this->space->id}/nodes/root", [
        'title' => 'С картой',
        'map_lat' => 38.5598,
        'map_lon' => 68.787,
        'map_title' => 'Душанбе',
    ]);

    $response->assertStatus(201);
    expect($response->json('map_title'))->toBe('Душанбе');

    $child = $this->postJson("/api/spaces/{$this->space->id}/nodes/{$this->node->id}/child", [
        'title' => 'Потомок с картой',
        'map_lat' => 1.5,
        'map_lon' => 2.5,
    ]);
    $child->assertStatus(201);
    expect($child->json('node.map_lat'))->toBe(1.5);
});
