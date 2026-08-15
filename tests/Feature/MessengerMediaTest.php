<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(MessageAttachment::DISK);
    $this->root = User::where('name', config('auth.root.username'))->firstOrFail();
    $this->global = Conversation::where('type', Conversation::TYPE_GLOBAL)->firstOrFail();
    $this->actingAs($this->root);
});

test('a voice message is created with its duration and can be played back', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'voice',
        // MediaRecorder часто пишет audio/webm, но контейнер (Matroska) от
        // video/webm content-sniffing не отличить — оба в allowlist'е.
        'file' => UploadedFile::fake()->create('voice-message.webm', 40, 'audio/webm'),
        'duration_ms' => 4200,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('type', 'voice');
    $response->assertJsonPath('attachment.duration_ms', 4200);

    $this->assertDatabaseHas('message_attachments', [
        'format' => 'webm',
        'mime' => 'audio/webm',
        'duration_ms' => 4200,
    ]);

    $message = Message::where('type', 'voice')->firstOrFail();
    $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$message->id}/attachment/preview")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'audio/webm');
});

test('a video message is created with its duration and can be played back', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'video',
        'file' => UploadedFile::fake()->create('video-message.webm', 512, 'video/webm'),
        'duration_ms' => 15000,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('type', 'video');
    $response->assertJsonPath('attachment.duration_ms', 15000);

    $message = Message::where('type', 'video')->firstOrFail();
    $this->get("/api/messenger/conversations/{$this->global->id}/messages/{$message->id}/attachment/preview")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'video/webm');
});

test('a voice message accepts a Safari-style mp4/m4a container', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'voice',
        'file' => UploadedFile::fake()->create('voice-message.m4a', 30, 'audio/mp4'),
    ]);

    $response->assertStatus(201);
});

test('a voice recording past the duration cap is rejected', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'voice',
        'file' => UploadedFile::fake()->create('voice-message.webm', 40, 'audio/webm'),
        'duration_ms' => 11 * 60 * 1000,
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('message_attachments', 0);
});

test('a video message rejects an mp3 disguised with a video extension', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'video',
        'file' => UploadedFile::fake()->create('video-message.webm', 100, 'audio/mpeg'),
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseCount('message_attachments', 0);
});

test('a voice message over the size cap is rejected', function () {
    $response = $this->post("/api/messenger/conversations/{$this->global->id}/messages", [
        'type' => 'voice',
        'file' => UploadedFile::fake()->create('voice-message.webm', 20481, 'audio/webm'),
    ]);

    $response->assertStatus(422);
});
