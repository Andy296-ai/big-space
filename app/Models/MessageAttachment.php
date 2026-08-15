<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    /** Приватный диск: storage/app/private, наружу напрямую не раздаётся — тот же, что у NodeAttachment. */
    public const DISK = 'local';

    /**
     * Форматы, которые можно открыть во встроенном просмотрщике. html —
     * дополнительно к тому, что поддерживает NodeAttachment: рендерится
     * только в песочнице (sandbox="allow-popups", без allow-scripts) —
     * см. MessageAttachmentController::preview(). ogg/m4a/wav — форматы
     * голосовых сообщений (webm/mp4 у них общие с видео, уже здесь) —
     * без них /attachment/preview отдавал бы 404 для <audio> плеера.
     */
    public const PREVIEWABLE_FORMATS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp4', 'webm', 'ogg', 'm4a', 'wav',
        'html', 'htm',
    ];

    protected $fillable = [
        'message_id',
        'label',
        'path',
        'size',
        'format',
        'mime',
        'duration_ms',
    ];

    protected $casts = [
        'size' => 'integer',
        'duration_ms' => 'integer',
    ];

    /** Путь в хранилище наружу не отдаём — клиенту он не нужен. */
    protected $hidden = ['path'];

    protected $appends = ['badge', 'previewable'];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function getBadgeAttribute(): string
    {
        return strtoupper($this->format);
    }

    public function getPreviewableAttribute(): bool
    {
        return in_array(strtolower($this->format), self::PREVIEWABLE_FORMATS, true);
    }

    /** Файл удаляем вместе с записью, иначе хранилище засорится. */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            Storage::disk(self::DISK)->delete($attachment->path);
        });
    }
}
