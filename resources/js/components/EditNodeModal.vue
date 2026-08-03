<script setup lang="ts">
import { X, Edit3, Palette, Tag } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useT } from '../lib/i18n';
import AttachmentEditor from './AttachmentEditor.vue';
import type { PendingAttachment } from './AttachmentEditor.vue';
import MapFields from './MapFields.vue';
import PositionFields from './PositionFields.vue';
import type { NodeData } from './SpaceScene.vue';

const props = defineProps<{
    node: NodeData | null;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'submit',
        payload: {
            id: number;
            title: string;
            description: string;
            color: string;
            tags: string;
            map_lat: number | null;
            map_lon: number | null;
            map_title: string | null;
            pos_x: number | null;
            pos_y: number | null;
            pending: PendingAttachment[];
        },
    ): void;
    (e: 'remove-attachment', attachmentId: number): void;
}>();

const title = ref('');
const description = ref('');
const color = ref('');
const tags = ref('');
const mapLat = ref('');
const mapLon = ref('');
const mapTitle = ref('');
const posX = ref('');
const posY = ref('');
const pending = ref<PendingAttachment[]>([]);

/** Пустое поле означает «точки нет», а не ноль. */
function toCoord(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' || Number.isNaN(Number(trimmed))
        ? null
        : Number(trimmed);
}

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

watch(
    () => props.node,
    (newNode) => {
        if (newNode) {
            title.value = newNode.title || '';
            description.value = newNode.description || '';
            color.value = newNode.color || '';
            tags.value = newNode.tags || '';
            mapLat.value = newNode.map_lat?.toString() ?? '';
            mapLon.value = newNode.map_lon?.toString() ?? '';
            mapTitle.value = newNode.map_title ?? '';
            posX.value = newNode.pos_x?.toString() ?? '';
            posY.value = newNode.pos_y?.toString() ?? '';
            pending.value = [];
        }
    },
    { immediate: true },
);

function handleSubmit() {
    if (!props.node) {
        return;
    }

    emit('submit', {
        id: props.node.id,
        title: title.value.trim(),
        description: description.value.trim(),
        color: color.value,
        tags: tags.value.trim(),
        map_lat: toCoord(mapLat.value),
        map_lon: toCoord(mapLon.value),
        map_title: mapTitle.value.trim() || null,
        pos_x: props.node.depth === 0 ? toCoord(posX.value) : null,
        pos_y: props.node.depth === 0 ? toCoord(posY.value) : null,
        pending: pending.value,
    });
}
</script>

<template>
    <div
        v-if="node"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
    >
        <div
            class="animate-in fade-in zoom-in-95 flex max-h-[90vh] w-full max-w-md flex-col rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between p-6 pb-4">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-amber-500/30 bg-amber-600/20 p-2 text-amber-400"
                    >
                        <Edit3 class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.editNodeTitle }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            Node #{{ node.id }}
                        </p>
                    </div>
                </div>
                <button
                    @click="emit('close')"
                    class="rounded-xl p-1.5 text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div
                class="min-h-0 flex-1 space-y-3.5 overflow-y-auto px-6 pb-2 text-xs"
            >
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.titleLabel
                    }}</label>
                    <input
                        v-model="title"
                        type="text"
                        :placeholder="t.nodeTitlePlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.descriptionLabel
                    }}</label>
                    <textarea
                        v-model="description"
                        rows="3"
                        :placeholder="t.nodeDetailsPlaceholder"
                        class="w-full resize-none rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label
                        class="mb-1.5 block flex items-center gap-1.5 font-semibold text-slate-300"
                    >
                        <Palette class="h-3.5 w-3.5 text-slate-400" />
                        <span>{{ t.customColorLabel }}</span>
                    </label>
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
                            Default
                        </button>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1 block flex items-center gap-1.5 font-semibold text-slate-300"
                    >
                        <Tag class="h-3.5 w-3.5 text-slate-400" />
                        <span>{{ t.tagsLabel }}</span>
                    </label>
                    <input
                        v-model="tags"
                        type="text"
                        :placeholder="t.tagsPlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <MapFields
                    v-model:lat="mapLat"
                    v-model:lon="mapLon"
                    v-model:map-title="mapTitle"
                />

                <PositionFields
                    v-if="node?.depth === 0"
                    v-model:pos-x="posX"
                    v-model:pos-y="posY"
                />

                <AttachmentEditor
                    v-model:pending="pending"
                    :saved="node?.attachments"
                    @remove-saved="emit('remove-attachment', $event)"
                />
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 p-6 pt-3"
            >
                <button
                    @click="emit('close')"
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    @click="handleSubmit"
                    class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500"
                >
                    {{ t.saveChanges }}
                </button>
            </div>
        </div>
    </div>
</template>
