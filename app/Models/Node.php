<?php

namespace App\Models;

use App\Events\SpaceUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $embedding вектор pgvector сырой строкой ("[0.1,0.2,...]") — не в
 *                                  $fillable/$casts (см. $hidden), Larastan не выводит её сам, только для
 *                                  null-проверок в PHP; сравнение/поиск делается в SQL через EmbeddingService/LinkSuggestionService.
 */
class Node extends Model
{
    /** Формы, которые умеет рисовать SpaceScene (см. resources/js/components/SpaceScene.vue). */
    public const SHAPES = ['circle', 'square', 'triangle', 'diamond', 'hexagon'];

    protected $fillable = [
        'space_id',
        'title',
        'description',
        'pos_x',
        'pos_y',
        'pos_z',
        'depth',
        'color',
        'default_color',
        'tags',
        'shape',
        'default_shape',
        'map_lat',
        'map_lon',
        'map_title',
        'tree_root_id',
        'linked_user_id',
        'linked_space_id',
        'logo_path',
    ];

    protected $casts = [
        'pos_x' => 'float',
        'pos_y' => 'float',
        'pos_z' => 'float',
        'depth' => 'integer',
        'tree_root_id' => 'integer',
        'map_lat' => 'float',
        'map_lon' => 'float',
        'linked_user_id' => 'integer',
        'linked_space_id' => 'integer',
        'locked_at' => 'datetime',
    ];

    /**
     * TTL страхует от забытой открытой вкладки/сессии без heartbeat —
     * EditNodeModal встраивает AttachmentEditor, где загрузка вложения до
     * 200 МБ может идти дольше короткого окна, отсюда не 5-10, а 15 минут.
     */
    public const LOCK_TTL_MINUTES = 15;

    /**
     * Путь на диске — деталь реализации, наружу отдаём готовый URL.
     * embedding — вектор pgvector, пишется только через
     * EmbeddingService::store() сырым SQL (не $node->update()) и намеренно
     * НЕ в $fillable: незачем модели уметь массово присваивать его, а
     * фронту он не нужен ни в каком виде.
     */
    protected $hidden = ['logo_path', 'embedding'];

    protected $appends = ['logo_url'];

    /** @return HasMany<NodeAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(NodeAttachment::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<NodeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(NodeRevision::class);
    }

    /** @return HasMany<NodeComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(NodeComment::class);
    }

    /** Карта рисуется только когда точка задана. */
    public function hasMap(): bool
    {
        return $this->map_lat !== null && $this->map_lon !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Заблокирован кем-то ДРУГИМ и блокировка ещё не истекла по TTL. */
    public function isLockedByOther(User $user): bool
    {
        return $this->locked_by !== null
            && $this->locked_by !== $user->id
            && $this->locked_at !== null
            && $this->locked_at->gt(now()->subMinutes(self::LOCK_TTL_MINUTES));
    }

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        // ?v=basename(logo_path) — сам путь у каждой загрузки свой (Laravel
        // генерирует случайное имя файла в store()), так что смена логотипа
        // меняет и URL. Без этого при замене логотипа URL оставался бы
        // буквально тем же самым, и логотип не обновился бы ни в браузерном
        // HTTP-кэше, ни в SpaceScene.vue's logoCache (Map, ключ — этот URL) —
        // до перезагрузки страницы был бы виден старый файл.
        $version = basename($this->logo_path);

        return "/api/spaces/{$this->space_id}/nodes/{$this->id}/logo?v={$version}";
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Этот узел представляет пользователя (только в Admin-пространстве).
     *
     * @return BelongsTo<User, $this>
     */
    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    /**
     * Этот узел представляет чьё-то пространство (только в Admin-пространстве).
     *
     * @return BelongsTo<Space, $this>
     */
    public function linkedSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'linked_space_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function treeRoot(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'tree_root_id');
    }

    /** @return HasMany<Edge, $this> */
    public function parentEdges(): HasMany
    {
        return $this->hasMany(Edge::class, 'child_id');
    }

    /** @return HasMany<Edge, $this> */
    public function childEdges(): HasMany
    {
        return $this->hasMany(Edge::class, 'parent_id');
    }

    /** @return BelongsToMany<Node, $this> */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'edges', 'child_id', 'parent_id');
    }

    /** @return BelongsToMany<Node, $this> */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'edges', 'parent_id', 'child_id');
    }

    /** Живое обновление: любое изменение узла будит всех, кто сейчас смотрит на это пространство. */
    protected static function booted(): void
    {
        static::created(fn (self $node) => SpaceUpdated::dispatch($node->space_id));
        static::updated(fn (self $node) => SpaceUpdated::dispatch($node->space_id));
        static::deleted(fn (self $node) => SpaceUpdated::dispatch($node->space_id));
    }
}
