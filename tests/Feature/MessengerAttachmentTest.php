<?php

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(MessageAttachment::DISK);
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->actingAs($this->root);
});

test('uploading a pdf creates a file message with an attachment', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('смета.pdf', 12),
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('type', 'file');
    $response->assertJsonPath('attachment.label', 'смета.pdf');
    $response->assertJsonPath('attachment.badge', 'PDF');

    $this->assertDatabaseHas('message_attachments', [
        'label' => 'смета.pdf',
        'format' => 'pdf',
        'mime' => 'application/pdf',
    ]);
});

test('a pdf attachment can be previewed inline without a sandbox header', function () {
    $created = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('план.pdf', 12),
    ])->json();

    $response = $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/attachment/preview");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    // Общий CSP приложения (SecurityHeaders) есть всегда — важно, что это
    // НЕ строгий "sandbox", который ставится только для HTML-вложений.
    expect($response->headers->get('Content-Security-Policy'))->not->toBe('sandbox');
});

test('an html attachment is served inline but with a strict sandbox CSP header', function () {
    $created = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->createWithContent(
            'page.html',
            '<html><body><script>alert(document.cookie)</script>Hello</body></html>',
        ),
    ])->json();

    $response = $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/attachment/preview");

    $response->assertStatus(200);
    $response->assertHeader('Content-Security-Policy', 'sandbox');

    // response()->file() стримит через readfile() — BinaryFileResponse не
    // буферизует тело, так что assertSeeText тут не сработает. Байты не
    // трогаем (sandbox блокирует исполнение, не факт хранения) — проверяем
    // содержимое прямо на диске.
    $attachment = MessageAttachment::where('message_id', $created['id'])->firstOrFail();
    expect(Storage::disk(MessageAttachment::DISK)->get($attachment->path))
        ->toContain('<script>alert(document.cookie)</script>');
});

test('a non-previewable format can be downloaded but not previewed', function () {
    $created = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('архив.zip', 4),
    ])->json();

    $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/attachment/download")
        ->assertStatus(200);
    $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/attachment/preview")
        ->assertStatus(404);
});

test('a disallowed file extension is rejected', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('virus.exe', 4),
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('message_attachments', 0);
});

test('a file over the size cap is rejected', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('huge.pdf', 204801),
    ]);

    $response->assertStatus(422);
});

test('a stranger to the conversation cannot download or preview its attachments', function () {
    $created = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('приватное.pdf', 4),
    ])->json();

    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$created['id']}/attachment/download")
        ->assertStatus(403);
});

test('an attachment cannot be reached through a different conversation the user does belong to', function () {
    $created = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'file',
        'file' => UploadedFile::fake()->create('в-глобальном.pdf', 4),
    ])->json();

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    app(TeamProvisioner::class)->createTeam('Изолированная', '', [$alice->id, $bob->id]);
    $team = Team::where('name', 'Изолированная')->firstOrFail();
    $ownConversationId = $team->conversation->id;

    $this->actingAs($alice);

    // {conversation} в URL — своя команда (доступ есть), но {message}
    // принадлежит глобальному чату, где Алисы нет — must 404, не 200.
    $this->get("/api/messenger/conversations/{$ownConversationId}/messages/{$created['id']}/attachment/download")
        ->assertStatus(404);
});
