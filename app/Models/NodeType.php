<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип узла, определяемый пользователем внутри пространства — набор полей
 * (field_schema) собирается из фиксированного набора видов (см.
 * FIELD_KINDS), а не изобретается заново. Один и тот же тип переиспользуется
 * любым числом узлов в этом пространстве (не привязан к одному дереву).
 */
class NodeType extends Model
{
    /**
     * Фиксированный тулбокс видов полей — гибкость в том, ИЗ ЧЕГО
     * пользователь собирает схему типа, а не в бесконечном наборе видов.
     * photo/files — виртуальные: значения не пишутся в custom_fields,
     * это чисто позиционная подсказка форме отрисовать существующий пикер
     * логотипа/вложений на этом месте (см. NodeTypeFieldsEditor.vue) —
     * переиспользуют уже готовый (превью, извлечение текста из PDF/DOCX,
     * приватное хранилище) пайплайн вместо повторной реализации внутри jsonb.
     */
    public const FIELD_KINDS = [
        'text', 'textarea', 'number', 'date', 'url', 'phone',
        'checkbox', 'select', 'multiselect', 'relation', 'photo', 'files',
    ];

    /** Не более одного слота — у узла один логотип и один общий список вложений. */
    public const SINGLETON_FIELD_KINDS = ['photo', 'files'];

    protected $fillable = [
        'space_id',
        'key',
        'label',
        'icon',
        'color',
        'field_schema',
    ];

    protected $casts = [
        'field_schema' => 'array',
    ];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(): array
    {
        return $this->field_schema ?? [];
    }
}
