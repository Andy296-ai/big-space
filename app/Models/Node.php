<?php

namespace App\Models;

use App\Events\SpaceUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    /** Путь на диске — деталь реализации, наружу отдаём готовый URL. */
    protected $hidden = ['logo_path'];

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

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return "/api/spaces/{$this->space_id}/nodes/{$this->id}/logo";
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
