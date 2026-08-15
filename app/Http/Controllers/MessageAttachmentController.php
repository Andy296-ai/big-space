<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    /**
     * Сообщение действительно из этого разговора, и у него вообще есть
     * вложение — у сообщения ровно одно (1:1), поэтому в маршруте нет
     * отдельного {attachment}, оно достаётся через связь, не биндингом.
     */
    private static function guard(Conversation $conversation, Message $message): MessageAttachment
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        $attachment = $message->attachment;
        abort_if($attachment === null, 404);

        return $attachment;
    }

    public function download(Conversation $conversation, Message $message): StreamedResponse
    {
        $attachment = self::guard($conversation, $message);

        $disk = Storage::disk(MessageAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        // Всегда как вложение: содержимое не должно исполняться в браузере.
        return $disk->download($attachment->path, $attachment->label ?: basename($attachment->path));
    }

    /**
     * HTML — только в песочнице: sandbox="allow-popups" на iframe со стороны
     * фронтенда (MessageAttachmentPreview.vue) НЕ единственная защита — сам
     * ответ здесь тоже получает Content-Security-Policy: sandbox, самую
     * строгую форму (без токенов, блокирует скрипты/формы/навигацию) —
     * сработает, даже если кто-то откроет ссылку на превью напрямую, в обход
     * iframe. SecurityHeaders намеренно не перетирает этот заголовок своим
     * общим CSP (см. комментарий там) — он и так строже.
     */
    public function preview(Request $request, Conversation $conversation, Message $message): BinaryFileResponse
    {
        $attachment = self::guard($conversation, $message);
        abort_unless($attachment->previewable, 404);

        $disk = Storage::disk(MessageAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        $format = strtolower($attachment->format);
        $isHtml = in_array($format, ['html', 'htm'], true);

        $response = response()->file($disk->path($attachment->path), [
            'Content-Type' => $isHtml ? 'text/html; charset=UTF-8' : ($attachment->mime ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline',
        ]);

        if ($isHtml) {
            $response->headers->set('Content-Security-Policy', 'sandbox');
        }

        return $response;
    }
}
