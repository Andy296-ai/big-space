<?php

/**
 * Стартовые заготовки для «Создать пространство»: пара типов узлов плюс
 * один-два примерных корня с этим типом — задел, который пользователь сам
 * дальше расширяет, а не готовый заполненный набор данных (см. план
 * "Flexible node types + space templates", §7). Применяется один раз, в
 * SpaceProvisioner::createForUser(), поверх всегда создаваемого корня
 * "Origin" — Origin остаётся, шаблонные корни это отдельные независимые
 * деревья рядом с ним.
 */
return [
    'job_search' => [
        'label' => 'Job search',
        'description' => 'Platforms you apply through, regions, and the contacts you deal with — with exact address, phone, files of what was done.',
        'node_types' => [
            [
                'key' => 'platform',
                'label' => 'Platform',
                'icon' => '💼',
                'color' => '#3b82f6',
                'field_schema' => [
                    ['key' => 'url', 'label' => 'URL', 'type' => 'url'],
                ],
            ],
            [
                'key' => 'region',
                'label' => 'Region',
                'icon' => '📍',
                'color' => '#10b981',
                'field_schema' => [],
            ],
            [
                'key' => 'contact',
                'label' => 'Contact',
                'icon' => '👤',
                'color' => '#f59e0b',
                'field_schema' => [
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone'],
                    ['key' => 'address', 'label' => 'Address', 'type' => 'text'],
                    ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo'],
                    ['key' => 'notes', 'label' => 'What was done', 'type' => 'textarea'],
                    ['key' => 'files', 'label' => 'Files', 'type' => 'files'],
                ],
            ],
        ],
        'roots' => [
            ['title' => 'Platforms', 'type_key' => 'platform', 'color' => '#3b82f6'],
        ],
    ],

    'contacts' => [
        'label' => 'Contacts',
        'description' => 'People you need to keep track of — phone, where you met them, photo, address.',
        'node_types' => [
            [
                'key' => 'contact',
                'label' => 'Contact',
                'icon' => '👤',
                'color' => '#f59e0b',
                'field_schema' => [
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone'],
                    ['key' => 'met_at', 'label' => 'Where you met', 'type' => 'text'],
                    ['key' => 'address', 'label' => 'Address', 'type' => 'text'],
                    ['key' => 'photo', 'label' => 'Photo', 'type' => 'photo'],
                ],
            ],
        ],
        'roots' => [
            ['title' => 'Contacts', 'type_key' => 'contact', 'color' => '#f59e0b'],
        ],
    ],

    'project' => [
        'label' => 'Project',
        'description' => 'Tasks with a status and due date, milestones, and how they relate to each other.',
        'node_types' => [
            [
                'key' => 'task',
                'label' => 'Task',
                'icon' => '✅',
                'color' => '#8b5cf6',
                'field_schema' => [
                    ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['To do', 'In progress', 'Done']],
                    ['key' => 'due', 'label' => 'Due date', 'type' => 'date'],
                    ['key' => 'assignee', 'label' => 'Assignee', 'type' => 'relation'],
                ],
            ],
            [
                'key' => 'milestone',
                'label' => 'Milestone',
                'icon' => '🚩',
                'color' => '#ec4899',
                'field_schema' => [
                    ['key' => 'due', 'label' => 'Due date', 'type' => 'date'],
                    ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ],
            ],
        ],
        'roots' => [
            ['title' => 'Tasks', 'type_key' => 'task', 'color' => '#8b5cf6'],
        ],
    ],

    'knowledge_base' => [
        'label' => 'Knowledge base',
        'description' => 'Articles and notes with a source link and a short summary.',
        'node_types' => [
            [
                'key' => 'article',
                'label' => 'Article',
                'icon' => '📄',
                'color' => '#06b6d4',
                'field_schema' => [
                    ['key' => 'source', 'label' => 'Source', 'type' => 'url'],
                    ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea'],
                    ['key' => 'files', 'label' => 'Files', 'type' => 'files'],
                ],
            ],
        ],
        'roots' => [
            ['title' => 'Articles', 'type_key' => 'article', 'color' => '#06b6d4'],
        ],
    ],
];
