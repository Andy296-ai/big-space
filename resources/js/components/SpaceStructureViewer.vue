<script setup lang="ts">
import { GitBranch, Loader2, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { EdgeData, NodeData } from './SpaceScene.vue';

const props = defineProps<{
    spaceId: number;
    title: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const loading = ref(true);
const nodes = ref<NodeData[]>([]);
const edges = ref<EdgeData[]>([]);

// Подсказка рисуется одна на всех через Teleport и следует за курсором —
// иначе будучи внутри строки она обрезалась бы скроллящимся контейнером.
const hoveredNode = ref<NodeData | null>(null);
const tooltipPos = ref({ x: 0, y: 0 });

function onRowEnter(node: NodeData, event: MouseEvent) {
    hoveredNode.value = node;
    positionTooltip(event);
}

function onRowMove(event: MouseEvent) {
    if (hoveredNode.value) {
        positionTooltip(event);
    }
}

function positionTooltip(event: MouseEvent) {
    const tooltipWidth = 256;
    const tooltipHeight = 140;

    tooltipPos.value = {
        x: Math.min(event.clientX + 16, window.innerWidth - tooltipWidth - 8),
        y: Math.min(event.clientY + 12, window.innerHeight - tooltipHeight - 8),
    };
}

interface TreeRow {
    node: NodeData;
    prefix: string;
}

const PRESET_COLORS = [
    '#3b82f6',
    '#10b981',
    '#8b5cf6',
    '#f59e0b',
    '#ec4899',
    '#06b6d4',
    '#84cc16',
    '#a855f7',
    '#f97316',
    '#14b8a6',
];

function nodeColor(node: NodeData): string {
    return node.color?.trim()
        ? node.color
        : PRESET_COLORS[
              Math.abs(node.tree_root_id ?? node.id) % PRESET_COLORS.length
          ];
}

/** Классический рекурсивный обход в стиле unix `tree`, склеенный в плоский список строк. */
const rows = computed<TreeRow[]>(() => {
    const childrenOf = new Map<number, number[]>();
    const hasParent = new Set<number>();

    edges.value.forEach((e) => {
        if (!childrenOf.has(e.parent_id)) {
            childrenOf.set(e.parent_id, []);
        }

        childrenOf.get(e.parent_id)?.push(e.child_id);
        hasParent.add(e.child_id);
    });

    const byId = new Map(nodes.value.map((n) => [n.id, n]));
    const visited = new Set<number>();
    const result: TreeRow[] = [];

    function walk(
        id: number,
        prefix: string,
        isLast: boolean,
        isRoot: boolean,
    ) {
        if (visited.has(id)) {
            return;
        }

        visited.add(id);

        const node = byId.get(id);

        if (!node) {
            return;
        }

        result.push({
            node,
            prefix: prefix + (isRoot ? '' : isLast ? '└─ ' : '├─ '),
        });

        const kids = childrenOf.get(id) ?? [];
        const childPrefix = prefix + (isRoot ? '' : isLast ? '   ' : '│  ');

        kids.forEach((childId, i) =>
            walk(childId, childPrefix, i === kids.length - 1, false),
        );
    }

    const roots = nodes.value.filter((n) => !hasParent.has(n.id));
    roots.forEach((root, i) => walk(root.id, '', i === roots.length - 1, true));

    // Недостижимые от корней узлы (циклы в сетевой структуре) — тоже не теряем.
    nodes.value.forEach((n) => {
        if (!visited.has(n.id)) {
            walk(n.id, '', true, true);
        }
    });

    return result;
});

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(`/api/spaces/${props.spaceId}/graph`);
        const data = await res.json();
        nodes.value = data.nodes ?? [];
        edges.value = data.edges ?? [];
    } catch (err) {
        console.error('Failed to load space structure:', err);
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex h-[80vh] w-[92vw] max-w-2xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <GitBranch class="h-4 w-4 shrink-0 text-emerald-400" />
                        <span class="truncate text-sm font-semibold">{{
                            title
                        }}</span>
                        <span
                            v-if="!loading"
                            class="shrink-0 text-[10px] text-slate-500"
                        >
                            {{ nodes.length }} {{ t.nodes }} ·
                            {{ edges.length }} {{ t.edges }}
                        </span>
                    </div>
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-4">
                    <div
                        v-if="loading"
                        class="flex h-full items-center justify-center text-slate-400"
                    >
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </div>
                    <div
                        v-else-if="!rows.length"
                        class="flex h-full items-center justify-center text-xs text-slate-500"
                    >
                        {{ t.structureEmptyLabel }}
                    </div>
                    <div v-else class="font-mono text-xs">
                        <div
                            v-for="row in rows"
                            :key="row.node.id"
                            class="flex items-center gap-1.5 rounded px-1 py-0.5 hover:bg-slate-800/60"
                            @mouseenter="onRowEnter(row.node, $event)"
                            @mousemove="onRowMove"
                            @mouseleave="hoveredNode = null"
                        >
                            <span class="whitespace-pre text-slate-600">{{
                                row.prefix
                            }}</span>
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: nodeColor(row.node),
                                }"
                            />
                            <span class="truncate text-slate-200">{{
                                row.node.title || t.untitledNode
                            }}</span>
                            <span class="shrink-0 text-[10px] text-slate-500"
                                >#{{ row.node.id }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Подробности при наведении: одна подсказка, следует за курсором -->
        <div
            v-if="hoveredNode"
            class="pointer-events-none fixed z-[60] w-64 rounded-xl border border-slate-700 bg-slate-950 p-3 text-[11px] shadow-2xl"
            :style="{ left: tooltipPos.x + 'px', top: tooltipPos.y + 'px' }"
        >
            <div class="mb-1 font-semibold text-slate-100">
                {{ hoveredNode.title || t.untitledNode }}
            </div>
            <p class="mb-2 text-slate-400">
                {{ hoveredNode.description || t.noDescription }}
            </p>
            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-slate-500"
            >
                <span>{{ t.depthLabel }}: {{ hoveredNode.depth }}</span>
                <span
                    >{{ t.coordinatesLabel }}:
                    {{ Math.round(hoveredNode.pos_x) }},
                    {{ Math.round(hoveredNode.pos_y) }}</span
                >
            </div>
            <div v-if="hoveredNode.tags" class="mt-1.5 flex flex-wrap gap-1">
                <span
                    v-for="tag in hoveredNode.tags.split(',').filter(Boolean)"
                    :key="tag"
                    class="rounded-full border border-slate-700 bg-slate-800 px-1.5 py-0.5 text-[10px] text-slate-300"
                    >{{ tag.trim() }}</span
                >
            </div>
        </div>
    </Teleport>
</template>
