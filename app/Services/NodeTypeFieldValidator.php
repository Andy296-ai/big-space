<?php

namespace App\Services;

use App\Models\Node;
use App\Models\NodeType;
use App\Models\Space;
use Illuminate\Validation\ValidationException;

/**
 * Единая точка проверки/очистки custom_fields под схему выбранного типа —
 * нужна на всех трёх точках мутации узла (createRoot/addChild/update в
 * GraphController), поэтому вынесена сюда, а не продублирована трижды.
 * Философия: тип и его схема ВСЕГДА перезагружаются с сервера по id и
 * скоупятся на пространство — присланной клиентом схеме не доверяем
 * никогда, только присланным значениям полей.
 */
class NodeTypeFieldValidator
{
    /** null id — валидный случай "тип не выбран", не ошибка. */
    public function resolveType(?int $nodeTypeId, Space $space): ?NodeType
    {
        if ($nodeTypeId === null) {
            return null;
        }

        $type = NodeType::where('space_id', $space->id)->find($nodeTypeId);

        if ($type === null) {
            throw ValidationException::withMessages([
                'node_type_id' => 'This node type does not exist in this space.',
            ]);
        }

        return $type;
    }

    /**
     * Без типа — custom_fields всегда null, что бы клиент ни прислал.
     * С типом — обязательные поля обязаны присутствовать, значения
     * приводятся/проверяются по виду поля, ключи вне текущей схемы (типа
     * успели изменить с прошлого сохранения этого узла) отбрасываются, а
     * не сохраняются как есть — иначе данные тихо разъезжались бы со
     * схемой, которую видит форма.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>|null
     */
    public function sanitize(?NodeType $type, array $submitted, Space $space): ?array
    {
        if ($type === null) {
            return null;
        }

        $clean = [];

        foreach ($type->fields() as $field) {
            $key = $field['key'] ?? null;
            $kind = $field['type'] ?? null;

            // photo/files — виртуальные позиционные подсказки форме
            // (см. NodeType::SINGLETON_FIELD_KINDS), значения не хранятся
            // здесь — фото уходит в logo_path, файлы — в node_attachments,
            // тем же путём, что и раньше.
            if ($key === null || in_array($kind, NodeType::SINGLETON_FIELD_KINDS, true)) {
                continue;
            }

            $value = $submitted[$key] ?? null;
            $isEmpty = $value === null || $value === '' || $value === [];

            if (($field['required'] ?? false) && $isEmpty) {
                throw ValidationException::withMessages([
                    "custom_fields.{$key}" => "The \"{$field['label']}\" field is required.",
                ]);
            }

            if ($isEmpty) {
                continue;
            }

            $clean[$key] = $this->coerce($kind, $key, $value, $field, $space);
        }

        return $clean === [] ? null : $clean;
    }

    /** @param  array<string, mixed>  $field */
    private function coerce(?string $kind, string $key, mixed $value, array $field, Space $space): mixed
    {
        return match ($kind) {
            'number' => is_numeric($value) ? (float) $value : $this->fail($key, $field, 'must be a number'),
            'date' => $this->parseDate($value) ?? $this->fail($key, $field, 'must be a valid date'),
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false ? (string) $value : $this->fail($key, $field, 'must be a valid URL'),
            'checkbox' => (bool) $value,
            'select' => $this->validatedOption($value, $field, $key),
            'multiselect' => $this->validatedMultiOptions($value, $field, $key),
            'relation' => $this->validatedRelation($value, $space, $key),
            default => (string) $value, // text, textarea, phone
        };
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** @param  array<string, mixed>  $field */
    private function validatedOption(mixed $value, array $field, string $key): string
    {
        $options = $field['options'] ?? [];

        if (! is_string($value) || ! in_array($value, $options, true)) {
            $this->fail($key, $field, 'must be one of the declared options');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    private function validatedMultiOptions(mixed $value, array $field, string $key): array
    {
        $options = $field['options'] ?? [];

        if (! is_array($value)) {
            $this->fail($key, $field, 'must be a list of the declared options');
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $options, true)) {
                $this->fail($key, $field, 'must be one of the declared options');
            }
        }

        return array_values($value);
    }

    /**
     * Тот же IDOR-принцип, что уже в GraphController::link() — цель
     * скоупится на пространство, а не доверяется как есть присланному id.
     */
    private function validatedRelation(mixed $value, Space $space, string $key): int
    {
        $id = is_numeric($value) ? (int) $value : null;
        $exists = $id !== null && Node::where('space_id', $space->id)->whereKey($id)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                "custom_fields.{$key}" => 'The related node was not found in this space.',
            ]);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $field */
    private function fail(string $key, array $field, string $reason): never
    {
        $label = $field['label'] ?? $key;

        throw ValidationException::withMessages([
            "custom_fields.{$key}" => "The \"{$label}\" field {$reason}.",
        ]);
    }
}
