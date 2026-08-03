<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    /** Строгое дерево: у узла не больше одного родителя, циклы запрещены. */
    public const STRUCTURE_TREE = 'tree';

    /** Направленный ациклический граф: несколько родителей, циклы запрещены. */
    public const STRUCTURE_DAG = 'dag';

    /** Сеть: направление связи необязательно, циклы разрешены. */
    public const STRUCTURE_NETWORK = 'network';

    /**
     * Уровневый (градуированный) граф: каждое ребро соединяет строго соседние
     * уровни, то есть depth ребёнка всегда равен depth родителя + 1. Циклы
     * при этом невозможны сами по себе — вдоль пути глубина только растёт.
     */
    public const STRUCTURE_LEVELED = 'leveled';

    public const STRUCTURES = [
        self::STRUCTURE_TREE,
        self::STRUCTURE_LEVELED,
        self::STRUCTURE_DAG,
        self::STRUCTURE_NETWORK,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'structure',
    ];

    protected $attributes = [
        'structure' => self::STRUCTURE_DAG,
    ];

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasMany<Edge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(Edge::class);
    }

    /** В дереве второй родитель запрещён. */
    public function allowsMultipleParents(): bool
    {
        return $this->structure !== self::STRUCTURE_TREE;
    }

    /** Циклы имеют смысл только в сети — у остальных структур они ломают обход. */
    public function allowsCycles(): bool
    {
        return $this->structure === self::STRUCTURE_NETWORK;
    }

    /** Ребро обязано соединять соседние уровни (depth ребёнка = depth родителя + 1). */
    public function requiresAdjacentLevels(): bool
    {
        return $this->structure === self::STRUCTURE_LEVELED;
    }
}
