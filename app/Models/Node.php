<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    protected $fillable = [
        'space_id',
        'title',
        'description',
        'pos_x',
        'pos_y',
        'pos_z',
        'depth',
        'color',
        'tags',
        'map_lat',
        'map_lon',
        'map_title',
        'tree_root_id',
    ];

    protected $casts = [
        'pos_x' => 'float',
        'pos_y' => 'float',
        'pos_z' => 'float',
        'depth' => 'integer',
        'tree_root_id' => 'integer',
        'map_lat' => 'float',
        'map_lon' => 'float',
    ];

    /** @return HasMany<NodeAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(NodeAttachment::class)->orderBy('position')->orderBy('id');
    }

    /** Карта рисуется только когда точка задана. */
    public function hasMap(): bool
    {
        return $this->map_lat !== null && $this->map_lon !== null;
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
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
}
