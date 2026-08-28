<script setup lang="ts">
import { Loader2, Search, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { NodeData } from './SpaceScene.vue';

interface NodeHit {
    id: number;
    title: string;
    color: string;
}

const props = defineProps<{
    spaceId: number;
    /**
     * Уже загруженные узлы пространства (Welcome.vue держит их для канваса
     * в любом случае) — только чтобы дёшево, без похода на сервер,
     * подписать уже выбранное значение при открытии формы редактирования.
     * Живой поиск по вводу идёт через отдельную скоуп-на-пространство
     * ручку (GraphController::searchNodes), не фильтрацией этого списка —
     * в большом пространстве список может быть big enough, что фильтрация
     * на клиенте и рендер полного дропдауна были бы неприятны.
     */
    allNodes?: NodeData[];
}>();

const value = defineModel<number | null>({ required: true });

const t = useT();

const query = ref('');
const results = ref<NodeHit[]>([]);
const loading = ref(false);
const open = ref(false);

const selectedLabel = ref<string | null>(null);

watch(
    value,
    (id) => {
        if (id === null) {
            selectedLabel.value = null;

            return;
        }

        selectedLabel.value =
            props.allNodes?.find((n) => n.id === id)?.title ?? `#${id}`;
    },
    { immediate: true },
);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function onInput() {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    const trimmed = query.value.trim();

    if (trimmed.length < 2) {
        results.value = [];
        open.value = trimmed.length > 0;

        return;
    }

    debounceTimer = setTimeout(async () => {
        loading.value = true;
        open.value = true;

        try {
            const res = await apiFetch(
                `/api/spaces/${props.spaceId}/nodes/search?q=${encodeURIComponent(trimmed)}`,
            );
            results.value = await res.json();
        } catch (err) {
            console.error('Node search failed:', err);
        } finally {
            loading.value = false;
        }
    }, 250);
}

function select(hit: NodeHit) {
    value.value = hit.id;
    selectedLabel.value = hit.title;
    query.value = '';
    results.value = [];
    open.value = false;
}

function clear() {
    value.value = null;
    selectedLabel.value = null;
}
</script>

<template>
    <div class="relative">
        <div
            v-if="selectedLabel !== null"
            class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100"
        >
            <span class="min-w-0 flex-1 truncate">{{ selectedLabel }}</span>
            <button
                type="button"
                @click="clear"
                :aria-label="t.clearAction"
                class="shrink-0 text-slate-400 hover:text-slate-200"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>

        <div v-else class="flex items-center gap-2">
            <Search class="h-3.5 w-3.5 shrink-0 text-slate-500" />
            <input
                v-model="query"
                type="text"
                :placeholder="t.relationFieldPlaceholder"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                @input="onInput"
                @focus="open = query.trim().length > 0"
            />
        </div>

        <div
            v-if="open && selectedLabel === null"
            class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-700 bg-slate-800 shadow-lg"
        >
            <div
                v-if="loading"
                class="flex items-center justify-center py-3 text-slate-400"
            >
                <Loader2 class="h-4 w-4 animate-spin" />
            </div>
            <p
                v-else-if="!results.length"
                class="px-3 py-2 text-[11px] text-slate-500"
            >
                {{ t.globalSearchEmpty }}
            </p>
            <button
                v-for="hit in results"
                v-else
                :key="hit.id"
                type="button"
                @click="select(hit)"
                class="flex w-full items-center gap-2 px-3 py-2 text-start text-xs text-slate-200 hover:bg-slate-700"
            >
                <span
                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: hit.color || '#3b82f6' }"
                />
                <span class="truncate">{{ hit.title }}</span>
            </button>
        </div>
    </div>
</template>
