<?php

namespace App\Models;

use App\Events\SpaceUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Edge extends Model
{
    protected $fillable = [
        'space_id',
        'parent_id',
        'child_id',
    ];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'parent_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'child_id');
    }

    protected static function booted(): void
    {
        static::created(fn (self $edge) => SpaceUpdated::dispatch($edge->space_id));
        static::deleted(fn (self $edge) => SpaceUpdated::dispatch($edge->space_id));
    }
}
