<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * 200 МБ на файл — с запасом под короткие видео, не только документы и картинки.
     * На проде это дополнительно ограничено upload_max_filesize/post_max_size
     * PHP (см. public/.user.ini) и лимитами веб-сервера (например,
     * client_max_body_size в nginx) — их нужно поднять отдельно.
     */
    private const MAX_KILOBYTES = 204800;

    /** Два миллиона символов на текстовый файл — с большим запасом для md/txt. */
    private const MAX_EDIT_CHARS = 2_000_000;

    /** Файлы крупнее этого не читаем при поиске по содержимому — не для интерактивного поиска. */
    private const MAX_SEARCHABLE_BYTES = 5 * 1024 * 1024;

    /** Короче этого запрос по содержимому не имеет смысла гонять по всем файлам. */
    private const MIN_SEARCH_QUERY_LENGTH = 2;

    /** MIME для inline-показа: расширение важнее автоопределения диска, у которого md/txt часто уходят в octet-stream. */
    private const PREVIEW_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'md' => 'text/markdown; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /** Убеждаемся, что узел и вложение действительно из этого пространства. */
    private static function guard(Space $space, Node $node, ?NodeAttachment $attachment = null): void
    {
        abort_unless($node->space_id === $space->id, 404);

        if ($attachment !== null) {
            abort_unless($attachment->node_id === $node->id, 404);
        }
    }

    /**
     * Принимает либо загруженный файл, либо внешнюю ссылку.
     */
    public function store(Request $request, Space $space, Node $node): JsonResponse
    {
        self::guard($space, $node);

        $validated = $request->validate([
            'kind' => 'required|in:file,link',
            'label' => 'nullable|string|max:255',
            // Ссылке нужен адрес, файлу — сам файл.
            'url' => 'required_if:kind,link|nullable|url|max:2048',
            'file' => 'required_if:kind,file|nullable|file|max:'.self::MAX_KILOBYTES,
        ]);

        // nullable-поля в validated отсутствуют целиком, а не приходят пустыми.
        $label = trim((string) ($validated['label'] ?? ''));
        $position = (int) $node->attachments()->max('position') + 1;

        if ($validated['kind'] === NodeAttachment::KIND_LINK) {
            $attachment = $node->attachments()->create([
                'kind' => NodeAttachment::KIND_LINK,
                'label' => $label !== '' ? $label : $validated['url'],
                'url' => $validated['url'],
                'position' => $position,
            ]);

            return response()->json($attachment, 201);
        }

        $file = $request->file('file');

        // Имя генерируем сами: из пользовательского имени берём только подпись,
        // иначе оно могло бы увести запись за пределы папки узла.
        $path = $file->store("attachments/{$node->id}", NodeAttachment::DISK);

        $attachment = $node->attachments()->create([
            'kind' => NodeAttachment::KIND_FILE,
            'label' => $label !== '' ? $label : $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'format' => substr($file->getClientOriginalExtension(), 0, 8),
            'position' => $position,
        ]);

        return response()->json($attachment, 201);
    }

    /** Отдаёт загруженный файл. Внешние ссылки скачивать нечего — они и так адрес. */
    public function download(
        Request $request,
        Space $space,
        Node $node,
        NodeAttachment $attachment,
    ): StreamedResponse {
        self::guard($space, $node, $attachment);
        abort_unless($attachment->stored, 404);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        self::logAccess($request, $space, $attachment, 'download');

        // Всегда как вложение: содержимое не должно исполняться в браузере.
        return $disk->download($attachment->path, $attachment->label ?: basename($attachment->path));
    }

    private static function logAccess(Request $request, Space $space, NodeAttachment $attachment, string $mode): void
    {
        ActivityLog::record(
            $request->user(),
            ActivityLog::ACTION_ATTACHMENT_ACCESSED,
            'attachment',
            $attachment->id,
            ['mode' => $mode, 'label' => $attachment->label],
            $space->id,
        );
    }

    public function destroy(Space $space, Node $node, NodeAttachment $attachment): JsonResponse
    {
        self::guard($space, $node, $attachment);

        // Файл в хранилище удаляется обработчиком deleting у модели.
        $attachment->delete();

        return response()->json(['message' => 'Attachment removed']);
    }

    /**
     * Отдаёт файл inline (не как вложение) — только для безопасного списка форматов.
     *
     * BinaryFileResponse, а не Storage::response(): видео и аудио требуют
     * поддержки Range-запросов (перемотка, а для файлов с moov-атомом в конце,
     * как у многих записей экрана, — вообще старта воспроизведения). Обычный
     * StreamedResponse Range целиком игнорирует и всегда отдаёт файл с начала.
     */
    public function preview(Request $request, Space $space, Node $node, NodeAttachment $attachment): BinaryFileResponse
    {
        self::guard($space, $node, $attachment);
        abort_unless($attachment->previewable, 404);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        self::logAccess($request, $space, $attachment, 'preview');

        $mime = self::PREVIEW_MIME_TYPES[strtolower($attachment->format)] ?? 'application/octet-stream';

        return response()->file($disk->path($attachment->path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
        ]);
    }

    /** Сырое содержимое текстового вложения — для редактора на фронте. */
    public function content(Request $request, Space $space, Node $node, NodeAttachment $attachment): JsonResponse
    {
        self::guard($space, $node, $attachment);
        abort_unless($attachment->editable, 404);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        self::logAccess($request, $space, $attachment, 'content');

        return response()->json(['content' => $disk->get($attachment->path)]);
    }

    /** Перезаписывает содержимое текстового вложения. */
    public function updateContent(Request $request, Space $space, Node $node, NodeAttachment $attachment): JsonResponse
    {
        self::guard($space, $node, $attachment);
        abort_unless($attachment->editable, 404);

        $validated = $request->validate([
            'content' => 'present|string|max:'.self::MAX_EDIT_CHARS,
        ]);

        $disk = Storage::disk(NodeAttachment::DISK);
        $disk->put($attachment->path, $validated['content']);

        $attachment->update(['size' => $disk->size($attachment->path)]);

        return response()->json($attachment->fresh());
    }

    /**
     * ID узлов, у которых текстовое вложение (md/txt) содержит запрос.
     * Название узла/тегов ищется на фронте — это добирает то, чего там не видно.
     */
    public function search(Request $request, Space $space): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_SEARCH_QUERY_LENGTH) {
            return response()->json(['node_ids' => []]);
        }

        $disk = Storage::disk(NodeAttachment::DISK);
        $needle = Str::lower($query);

        $nodeIds = NodeAttachment::query()
            ->whereHas('node', fn ($q) => $q->where('space_id', $space->id))
            ->whereNotNull('path')
            ->get(['node_id', 'path', 'format', 'size'])
            ->filter(function (NodeAttachment $attachment) use ($disk, $needle) {
                if (! $attachment->editable || $attachment->size > self::MAX_SEARCHABLE_BYTES) {
                    return false;
                }

                return $disk->exists($attachment->path)
                    && str_contains(Str::lower($disk->get($attachment->path)), $needle);
            })
            ->pluck('node_id')
            ->unique()
            ->values();

        return response()->json(['node_ids' => $nodeIds]);
    }
}
