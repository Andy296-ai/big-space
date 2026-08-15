<?php

namespace App\Http\Controllers;

use App\Events\MessagePosted;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MessageController extends Controller
{
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    /** 200 МБ — тот же потолок, что и у вложений к узлам (AttachmentController::MAX_KILOBYTES); тем же лимитом делится video. */
    private const MAX_FILE_KILOBYTES = 204800;

    /** Голосовые — короткие, 20 МБ с большим запасом даже для длинной записи на opus. */
    private const MAX_VOICE_KILOBYTES = 20480;

    /** Голосовое — не многочасовая запись: 10 минут потолок. */
    private const MAX_VOICE_DURATION_MS = 10 * 60 * 1000;

    /** Видео-сообщение — короткая заметка, а не полноценный ролик: 5 минут потолок. */
    private const MAX_VIDEO_DURATION_MS = 5 * 60 * 1000;

    /**
     * Настоящий allowlist по содержимому файла (Laravel's mimes:/mimetypes:
     * используют content-sniffing через finfo, а не доверяют расширению или
     * Content-Type от клиента) — в отличие от NodeAttachment::store(), у
     * которого такой проверки нет вообще (известный, сознательно не
     * исправленный там пробел из более раннего security-прохода). Здесь —
     * новый путь загрузки, повторять пробел незачем.
     */
    private const ALLOWED_FILE_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm',
        'md', 'txt', 'html', 'htm', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'csv',
    ];

    /**
     * Только mimetypes: (не mimes:) — тут решает именно точный MIME, не его
     * производное расширение. Symfony's MimeTypes::getExtensions() отражает
     * audio/webm в "weba", а не "webm" (MediaRecorder на большинстве браузеров
     * пишет ровно audio/webm), так что mimes:webm его бы отверг — не подмена,
     * а особенность маппинга контейнер→расширение. video/webm в allowlist'е
     * по той же причине: контейнер (Matroska) от чисто аудио не отличить.
     * То же для audio/mp4 vs video/mp4 (Safari-фолбэк).
     */
    private const ALLOWED_VOICE_MIMES = [
        'audio/webm', 'video/webm',
        'audio/ogg', 'video/ogg',
        'audio/mp4', 'video/mp4',
        'audio/x-m4a', 'audio/m4a',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
    ];

    private const ALLOWED_VIDEO_MIMES = ['video/webm', 'video/mp4', 'video/ogg'];

    /** Курсорная пагинация по id — see план: "before_id cursor", не offset (тот дрейфует под параллельными вставками). */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $limit = min((int) $request->query('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
        $beforeId = $request->query('before_id');

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'attachment'])
            ->when($beforeId, fn ($q) => $q->where('id', '<', (int) $beforeId))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json($messages);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $type = $request->input('type', Message::TYPE_TEXT);

        $message = match ($type) {
            Message::TYPE_FILE => $this->storeFileMessage($request, $conversation),
            Message::TYPE_VOICE => $this->storeVoiceMessage($request, $conversation),
            Message::TYPE_VIDEO => $this->storeVideoMessage($request, $conversation),
            default => $this->storeTextMessage($request, $conversation),
        };

        $conversation->touch();

        $recipientIds = $conversation->participants()
            ->where('user_id', '!=', $request->user()->id)
            ->pluck('users.id')
            ->all();

        if ($recipientIds !== []) {
            MessagePosted::dispatch($conversation->id, $recipientIds);
        }

        return response()->json($message->load(['sender:id,name', 'attachment']), 201);
    }

    private function storeTextMessage(Request $request, Conversation $conversation): Message
    {
        // Триммим ДО валидации: иначе строка из одних пробелов проходит
        // required (тот отвергает только null/пустую строку/пустой массив) —
        // тот же фикс, что уже применён в NodeCommentController::store().
        $request->merge(['body' => trim((string) $request->input('body', ''))]);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        return $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'type' => Message::TYPE_TEXT,
            'body' => $validated['body'],
        ]);
    }

    private function storeFileMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KILOBYTES,
                'mimes:'.implode(',', self::ALLOWED_FILE_EXTENSIONS),
            ],
        ]);

        return $this->createMediaMessage($request, $conversation, Message::TYPE_FILE, $validated['file']);
    }

    private function storeVoiceMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_VOICE_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_VOICE_MIMES),
            ],
            'duration_ms' => 'nullable|integer|min:0|max:'.self::MAX_VOICE_DURATION_MS,
        ]);

        return $this->createMediaMessage(
            $request,
            $conversation,
            Message::TYPE_VOICE,
            $validated['file'],
            $validated['duration_ms'] ?? null,
        );
    }

    private function storeVideoMessage(Request $request, Conversation $conversation): Message
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KILOBYTES,
                'mimetypes:'.implode(',', self::ALLOWED_VIDEO_MIMES),
            ],
            'duration_ms' => 'nullable|integer|min:0|max:'.self::MAX_VIDEO_DURATION_MS,
        ]);

        return $this->createMediaMessage(
            $request,
            $conversation,
            Message::TYPE_VIDEO,
            $validated['file'],
            $validated['duration_ms'] ?? null,
        );
    }

    private function createMediaMessage(
        Request $request,
        Conversation $conversation,
        string $type,
        UploadedFile $file,
        ?int $durationMs = null,
    ): Message {
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'type' => $type,
            'body' => null,
        ]);

        $path = $file->store("messenger/{$conversation->id}/{$message->id}", MessageAttachment::DISK);

        MessageAttachment::create([
            'message_id' => $message->id,
            'label' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'format' => substr($file->getClientOriginalExtension(), 0, 8),
            // Определено по содержимому (finfo), не по расширению и не по
            // заголовку от клиента — см. класс-комментарий выше.
            'mime' => (string) $file->getMimeType(),
            'duration_ms' => $durationMs,
        ]);

        return $message;
    }

    /** Отмечает разговор прочитанным до текущего момента для текущего пользователя. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->participants()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
        ]);

        return response()->json(['message' => 'ok']);
    }
}
