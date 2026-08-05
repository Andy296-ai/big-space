<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Снимок узла ДО правки — заполняется в GraphController::update() перед тем,
 * как применить новые значения. Restore берёт снимок и накатывает его как
 * обычное обновление, попутно снимая ещё один снимок — поэтому откат сам
 * по себе тоже откатываем.
 */
class NodeRevision extends Model
{
    /** Сколько последних снимков держим на узел — старее просто не нужны. */
    public const MAX_PER_NODE = 50;

    public const UPDATED_AT = null;

    protected $fillable = [
        'node_id',
        'editor_id',
        'title',
        'description',
        'color',
        'tags',
        'shape',
        'map_lat',
        'map_lon',
        'map_title',
        'pos_x',
        'pos_y',
    ];

    protected $casts = [
        'map_lat' => 'float',
        'map_lon' => 'float',
        'pos_x' => 'float',
        'pos_y' => 'float',
    ];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Снимает текущее (ещё не изменённое) состояние узла и подрезает историю до лимита. */
    public static function snapshot(Node $node, ?User $editor): void
    {
        self::create([
            'node_id' => $node->id,
            'editor_id' => $editor?->id,
            'title' => $node->title,
            'description' => $node->description,
            'color' => $node->color,
            'tags' => $node->tags,
            'shape' => $node->shape,
            'map_lat' => $node->map_lat,
            'map_lon' => $node->map_lon,
            'map_title' => $node->map_title,
            'pos_x' => $node->pos_x,
            'pos_y' => $node->pos_y,
        ]);

        // id растёт строго по мере вставки, поэтому как граница по времени
        // годится не хуже created_at — а SQLite не умеет в голый OFFSET без
        // LIMIT, так что через first() (он сам добавляет LIMIT 1) находим
        // порог и одним delete() убираем всё, что старше.
        $cutoff = self::where('node_id', $node->id)
            ->orderByDesc('id')
            ->skip(self::MAX_PER_NODE - 1)
            ->first(['id']);

        if ($cutoff !== null) {
            self::where('node_id', $node->id)->where('id', '<', $cutoff->id)->delete();
        }
    }
}
