<?php

namespace App\Models;

use App\Events\SpaceUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class NodeAttachment extends Model
{
    /** Файл предлагается скачать. */
    public const KIND_FILE = 'file';

    /** Ссылка открывается в новой вкладке. */
    public const KIND_LINK = 'link';

    public const KINDS = [self::KIND_FILE, self::KIND_LINK];

    /** Приватный диск: storage/app/private, наружу напрямую не раздаётся. */
    public const DISK = 'local';

    /** Форматы, которые можно открыть во встроенном просмотрщике, а не только скачать. */
    public const PREVIEWABLE_FORMATS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'md', 'txt', 'mp4', 'webm'];

    /** Подмножество превью-форматов, которые можно ещё и редактировать прямо в приложении. */
    public const EDITABLE_FORMATS = ['md', 'txt'];

    protected $fillable = [
        'node_id',
        'kind',
        'label',
        'url',
        'path',
        'size',
        'format',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
        'size' => 'integer',
    ];

    /** Путь в хранилище наружу не отдаём — клиенту он не нужен. */
    protected $hidden = ['path'];

    /** Клиенту бейдж и признак «файл лежит у нас» нужны готовыми. */
    protected $appends = ['badge', 'stored', 'previewable', 'editable'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function getBadgeAttribute(): string
    {
        return $this->resolvedFormat();
    }

    public function getStoredAttribute(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /** Открывается во встроенном просмотрщике вместо скачивания. */
    public function getPreviewableAttribute(): bool
    {
        return $this->stored && in_array(strtolower($this->format), self::PREVIEWABLE_FORMATS, true);
    }

    /** Текстовые форматы, содержимое которых можно перезаписать через API. */
    public function getEditableAttribute(): bool
    {
        return $this->stored && in_array(strtolower($this->format), self::EDITABLE_FORMATS, true);
    }

    /** Бейдж справа: берём из явного поля, иначе выводим из расширения в ссылке. */
    public function resolvedFormat(): string
    {
        if ($this->format !== '') {
            return strtoupper($this->format);
        }

        // На битой ссылке parse_url возвращает false — тогда бейджа просто нет.
        $path = parse_url((string) $this->url, PHP_URL_PATH);

        if (! is_string($path)) {
            return '';
        }

        return strtoupper(substr(pathinfo($path, PATHINFO_EXTENSION), 0, 4));
    }

    /** Файл удаляем вместе с записью, иначе хранилище засорится. */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            if ($attachment->stored) {
                Storage::disk(self::DISK)->delete($attachment->path);
            }
        });

        static::created(fn (self $a) => SpaceUpdated::dispatch($a->node->space_id));
        static::updated(fn (self $a) => SpaceUpdated::dispatch($a->node->space_id));
        static::deleted(fn (self $a) => SpaceUpdated::dispatch($a->node->space_id));
    }
}
