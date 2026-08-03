<?php

namespace Database\Seeders;

use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use Illuminate\Database\Seeder;

/**
 * Второе дерево в текущем пространстве — чтобы проверить новые блоки панели
 * узла: карту, вложения, переполнение тегов и их отсутствие.
 */
class SampleTreeSeeder extends Seeder
{
    private const ROOT_TITLE = 'Проект «Душанбе»';

    public function run(): void
    {
        $space = Space::first();

        // Сеять некуда либо дерево уже стоит — выходим молча, чтобы повторный
        // запуск не плодил дубли.
        if (! $space || Node::where('space_id', $space->id)->where('title', self::ROOT_TITLE)->exists()) {
            return;
        }

        // Ставим в стороне от существующего дерева, чтобы не наложились.
        $root = $this->node($space, self::ROOT_TITLE, 700, 0, 0, [
            'description' => 'Корень проверочного дерева: у него есть карта, теги и вложения.',
            'color' => '#f59e0b',
            'tags' => 'проект,душанбе,2026,план,карта,демо',
            'map_lat' => 38.5598,
            'map_lon' => 68.787,
            'map_title' => 'Душанбе, площадь Дусти',
        ]);

        $this->attach($root, [
            ['file', 'Устав проекта', 'https://example.com/files/charter.pdf', 'PDF'],
            ['link', 'Сайт проекта', 'https://example.com', 'COM'],
        ]);

        // Узел с картой, но без вложений.
        $site = $this->node($space, 'Площадка', 520, -150, 1, [
            'description' => 'Только карта, вложений нет.',
            'color' => '#10b981',
            'tags' => 'локация',
            'map_lat' => 38.5737,
            'map_lon' => 68.7739,
            'map_title' => 'Северная площадка',
        ]);
        $this->edge($space, $root, $site);

        // Узел с длинным списком вложений — проверяет сворачивание.
        $docs = $this->node($space, 'Документы', 700, -150, 1, [
            'description' => 'Семь вложений: список должен схлопываться.',
            'color' => '#3b82f6',
            'tags' => 'документы,архив',
        ]);
        $this->edge($space, $root, $docs);

        $this->attach($docs, [
            ['file', 'Техническое задание', 'https://example.com/files/spec.pdf', 'PDF'],
            ['file', 'Исходники макетов', 'https://example.com/files/mockups.zip', 'ZIP'],
            ['file', 'Образ системы', 'https://example.com/files/system.iso', 'ISO'],
            ['link', 'Регламент онлайн', 'https://example.com/rules', 'COM'],
            ['file', 'Заметки', 'https://example.com/files/notes.md', 'MD'],
            ['file', 'Схема площадки', 'https://example.com/files/plan.png', 'PNG'],
            ['file', 'Смета', 'https://example.com/files/budget.pdf', 'PDF'],
        ]);

        // Совсем пустой узел: ни карты, ни вложений, ни тегов.
        $team = $this->node($space, 'Команда', 880, -150, 1, [
            'description' => 'Ни карты, ни вложений, ни тегов — блоки не должны появляться.',
            'color' => '#8b5cf6',
        ]);
        $this->edge($space, $root, $team);

        $lead = $this->node($space, 'Руководитель', 880, -300, 2, [
            'color' => '#ec4899',
            'tags' => 'роль',
        ]);
        $this->edge($space, $team, $lead);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function node(Space $space, string $title, float $x, float $y, int $depth, array $extra = []): Node
    {
        $node = Node::create([
            'space_id' => $space->id,
            'title' => $title,
            'description' => $extra['description'] ?? '',
            'pos_x' => $x,
            'pos_y' => $y,
            'pos_z' => 0,
            'depth' => $depth,
            'color' => $extra['color'] ?? '',
            'tags' => $extra['tags'] ?? '',
            'map_lat' => $extra['map_lat'] ?? null,
            'map_lon' => $extra['map_lon'] ?? null,
            'map_title' => $extra['map_title'] ?? null,
        ]);

        if ($depth === 0) {
            $node->update(['tree_root_id' => $node->id]);
        }

        return $node;
    }

    private function edge(Space $space, Node $parent, Node $child): void
    {
        Edge::create([
            'space_id' => $space->id,
            'parent_id' => $parent->id,
            'child_id' => $child->id,
        ]);

        $child->update(['tree_root_id' => $parent->tree_root_id ?? $parent->id]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string}>  $items
     */
    private function attach(Node $node, array $items): void
    {
        foreach ($items as $position => [$kind, $label, $url, $format]) {
            $node->attachments()->create([
                'kind' => $kind === 'link' ? NodeAttachment::KIND_LINK : NodeAttachment::KIND_FILE,
                'label' => $label,
                'url' => $url,
                'format' => $format,
                'position' => $position,
            ]);
        }
    }
}
