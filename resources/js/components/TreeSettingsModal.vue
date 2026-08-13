<script setup lang="ts">
import {
    Circle,
    Diamond,
    Hexagon,
    Loader2,
    Settings2,
    Square,
    Triangle,
    X,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { NodeData, NodeShape } from './SpaceScene.vue';

const props = defineProps<{
    spaceId: number;
    node: NodeData;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'updated', node: NodeData): void;
}>();

const t = useT();

const shape = ref<NodeShape>('circle');
const color = ref('');
const saving = ref(false);
const applying = ref(false);

watch(
    () => props.node,
    (node) => {
        shape.value = node.default_shape ?? 'circle';
        color.value = node.default_color ?? '';
    },
    { immediate: true },
);

const shapeOptions: {
    id: NodeShape;
    icon: typeof Circle;
    labelKey:
        | 'shapeCircle'
        | 'shapeSquare'
        | 'shapeTriangle'
        | 'shapeDiamond'
        | 'shapeHexagon';
}[] = [
    { id: 'circle', icon: Circle, labelKey: 'shapeCircle' },
    { id: 'square', icon: Square, labelKey: 'shapeSquare' },
    { id: 'triangle', icon: Triangle, labelKey: 'shapeTriangle' },
    { id: 'diamond', icon: Diamond, labelKey: 'shapeDiamond' },
    { id: 'hexagon', icon: Hexagon, labelKey: 'shapeHexagon' },
];

const presetColors = [
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

async function submit(applyToAll: boolean) {
    if (saving.value || applying.value) {
        return;
    }

    (applyToAll ? applying : saving).value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/tree-settings`,
            {
                method: 'PUT',
                body: JSON.stringify({
                    default_shape: shape.value,
                    default_color: color.value || null,
                    apply_to_all: applyToAll,
                }),
            },
        );
        emit('updated', await res.json());
    } catch (err) {
        console.error('Failed to save tree settings:', err);
    } finally {
        saving.value = false;
        applying.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex w-[92vw] max-w-md flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3.5"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div
                            class="rounded-xl border border-violet-500/30 bg-violet-600/20 p-2 text-violet-400"
                        >
                            <Settings2 class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold">
                                {{ t.treeSettingsTitle }}
                            </h3>
                            <p class="truncate text-[11px] text-slate-400">
                                {{ node.title || t.untitledNode }}
                            </p>
                        </div>
                    </div>
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="space-y-4 p-5 text-xs">
                    <p class="text-slate-400">{{ t.treeSettingsHint }}</p>

                    <div>
                        <label
                            class="mb-1.5 block font-semibold text-slate-300"
                            >{{ t.defaultShapeLabel }}</label
                        >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-for="opt in shapeOptions"
                                :key="opt.id"
                                type="button"
                                @click="shape = opt.id"
                                :title="t[opt.labelKey]"
                                :aria-label="t[opt.labelKey]"
                                class="rounded-xl border p-2 transition-all"
                                :class="
                                    shape === opt.id
                                        ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                                        : 'border-slate-700 bg-slate-800 text-slate-400 hover:text-slate-200'
                                "
                            >
                                <component :is="opt.icon" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div>
                        <label
                            class="mb-1.5 block font-semibold text-slate-300"
                            >{{ t.defaultColorLabel }}</label
                        >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-for="c in presetColors"
                                :key="c"
                                type="button"
                                @click="color = c"
                                class="h-6 w-6 rounded-full border border-white/20 transition-transform active:scale-95"
                                :class="{
                                    'ring-2 ring-white ring-offset-2 ring-offset-slate-900':
                                        color === c,
                                }"
                                :style="{ backgroundColor: c }"
                            />
                            <button
                                type="button"
                                @click="color = ''"
                                class="rounded-lg border border-slate-700 bg-slate-800 px-2 py-1 text-[10px] text-slate-400 hover:text-slate-200"
                            >
                                {{ t.defaultColorNone }}
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="flex flex-col gap-2 border-t border-slate-800 p-5 pt-3"
                >
                    <button
                        :disabled="saving || applying"
                        @click="submit(false)"
                        class="flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow transition-all hover:bg-blue-500 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Loader2
                            v-if="saving"
                            class="h-3.5 w-3.5 animate-spin"
                        />
                        <span>{{ t.saveDefaultsAction }}</span>
                    </button>
                    <button
                        :disabled="saving || applying"
                        @click="submit(true)"
                        class="flex items-center justify-center gap-1.5 rounded-xl border border-amber-500/40 bg-amber-950/30 px-3 py-2 text-xs font-semibold text-amber-300 transition-all hover:bg-amber-900/40 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Loader2
                            v-if="applying"
                            class="h-3.5 w-3.5 animate-spin"
                        />
                        <span>{{ t.applyToAllAction }}</span>
                    </button>
                    <p class="text-center text-[10px] text-slate-500">
                        {{ t.applyToAllHint }}
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>
