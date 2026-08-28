<?php

namespace App\Services;

use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeType;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Создание пространств для пользователей. Помимо самого пространства
 * следит за "зеркалом" в Admin-пространстве: если у пользователя там есть
 * узел (см. Node::linked_user_id), новое пространство появляется под ним
 * ещё одним дочерним узлом (Node::linked_space_id).
 */
class SpaceProvisioner
{
    public function createForUser(
        User $user,
        string $name,
        string $description = '',
        string $structure = Space::STRUCTURE_DAG,
        ?string $template = null,
    ): Space {
        $space = Space::create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'description' => $description,
            'structure' => $structure,
        ]);

        $root = Node::create([
            'space_id' => $space->id,
            'title' => 'Origin',
            'description' => 'The starting node of your space.',
            'pos_x' => 0,
            'pos_y' => 0,
            'pos_z' => 0,
            'depth' => 0,
            'color' => '#3b82f6',
            'tags' => 'origin,root',
        ]);
        $root->update(['tree_root_id' => $root->id]);

        if ($template !== null) {
            $this->applyTemplate($space, $template);
        }

        $this->linkIntoAdminSpace($user, $space);

        return $space;
    }

    /**
     * Заводит пару типов узлов и один-два примерных корня рядом с "Origin"
     * (см. config/space_templates.php). Неизвестный ключ шаблона молча
     * игнорируется — пространство всё равно создаётся с одним "Origin",
     * как и раньше без шаблона.
     */
    private function applyTemplate(Space $space, string $template): void
    {
        $config = config("space_templates.{$template}");

        if ($config === null) {
            return;
        }

        $typeIdsByKey = [];

        foreach ($config['node_types'] ?? [] as $typeDef) {
            $type = NodeType::create([
                'space_id' => $space->id,
                'key' => $typeDef['key'],
                'label' => $typeDef['label'],
                'icon' => $typeDef['icon'] ?? null,
                'color' => $typeDef['color'] ?? null,
                'field_schema' => $typeDef['field_schema'] ?? [],
            ]);
            $typeIdsByKey[$typeDef['key']] = $type->id;
        }

        foreach (($config['roots'] ?? []) as $index => $rootDef) {
            $root = Node::create([
                'space_id' => $space->id,
                'title' => $rootDef['title'],
                'pos_x' => 260 * ($index + 1),
                'pos_y' => 0,
                'pos_z' => 0,
                'depth' => 0,
                'color' => $rootDef['color'] ?? null,
                'node_type_id' => $typeIdsByKey[$rootDef['type_key']] ?? null,
            ]);
            $root->update(['tree_root_id' => $root->id]);
        }
    }

    /** Свободный slug на основе названия. */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'space';
        $slug = $base;
        $suffix = 1;

        while (Space::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** Если у пользователя есть узел в Admin-пространстве — вешаем под него узел этого пространства. */
    public function linkIntoAdminSpace(User $user, Space $space): void
    {
        $userNode = $user->linkedNode()->first();

        if ($userNode === null) {
            return;
        }

        $childCount = $userNode->childEdges()->count();
        $pos = LayoutEngine::placeChild($userNode, $childCount);

        $spaceNode = Node::create([
            'space_id' => $userNode->space_id,
            'title' => $space->name,
            'description' => '',
            'pos_x' => $pos['x'],
            'pos_y' => $pos['y'],
            'pos_z' => $pos['z'],
            'depth' => $userNode->depth + 1,
            'tree_root_id' => $userNode->tree_root_id ?? $userNode->id,
            'shape' => 'square',
            'linked_space_id' => $space->id,
        ]);

        Edge::create([
            'space_id' => $userNode->space_id,
            'parent_id' => $userNode->id,
            'child_id' => $spaceNode->id,
        ]);
    }
}
