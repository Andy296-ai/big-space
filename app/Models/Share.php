<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Публичная read-only ссылка — на всё пространство (node_id = null) или на
 * поддерево конкретного узла. Только $timestamps = false: created_at есть,
 * updated_at — нет, ссылку не редактируют, только пересоздают (см.
 * ShareController::store() — "regenerate заменяет существующую").
 */
class Share extends Model
{
    public $timestamps = false;

    protected $fillable = ['space_id', 'node_id', 'token', 'created_by', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Публичные роуты биндятся по токену ({share:token}), не по id — id нигде наружу не светится. */
    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
