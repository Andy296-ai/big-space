<script setup lang="ts">
import { Loader2, Search, X } from 'lucide-vue-next';
import { nextTick, onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';

interface SearchResult {
    id: number;
    title: string;
    description: string | null;
    depth: number;
    space: { id: number; name: string; slug: string };
}

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select', payload: { spaceSlug: string; nodeId: number }): void;
}>();

const t = useT();

const query = ref('');
const results = ref<SearchResult[]>([]);
const loading = ref(false);
const searched = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function onInput() {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    const trimmed = query.value.trim();

    if (trimmed.length < 2) {
        results.value = [];
        searched.value = false;

        return;
    }

    debounceTimer = setTimeout(async () => {
        loading.value = true;

        try {
            const res = await apiFetch(
                `/api/search?q=${encodeURIComponent(trimmed)}`,
            );
            const data = await res.json();
            results.value = data.results ?? [];
        } catch (err) {
            console.error('Global search failed:', err);
        } finally {
            loading.value = false;
            searched.value = true;
        }
    }, 250);
}

function select(result: SearchResult) {
    emit('select', { spaceSlug: result.space.slug, nodeId: result.id });
}

onMounted(async () => {
    await nextTick();
    inputRef.value?.focus();
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/70 p-4 pt-[12vh] backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex max-h-[70vh] w-[92vw] max-w-xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center gap-2.5 border-b border-slate-800 px-4 py-3"
                >
                    <Search class="h-4 w-4 shrink-0 text-slate-400" />
                    <input
                        ref="inputRef"
                        v-model="query"
                        type="text"
                        :placeholder="t.globalSearchPlaceholder"
                        class="w-full bg-transparent text-sm text-slate-100 placeholder-slate-400 focus:outline-none"
                        @input="onInput"
                        @keydown.escape="emit('close')"
                    />
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-2">
                    <div
                        v-if="loading"
                        class="flex items-center justify-center py-8 text-slate-400"
                    >
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </div>
                    <p
                        v-else-if="searched && !results.length"
                        class="py-8 text-center text-xs text-slate-500"
                    >
                        {{ t.globalSearchEmpty }}
                    </p>
                    <p
                        v-else-if="!searched"
                        class="py-8 text-center text-xs text-slate-500"
                    >
                        {{ t.globalSearchHint }}
                    </p>
                    <div v-else class="space-y-1">
                        <button
                            v-for="result in results"
                            :key="result.id"
                            type="button"
                            @click="select(result)"
                            class="flex w-full flex-col gap-0.5 rounded-2xl px-3 py-2.5 text-start transition-colors hover:bg-slate-800"
                        >
                            <div class="flex items-center gap-2 text-xs">
                                <span
                                    class="truncate font-semibold text-slate-100"
                                    >{{ result.title || t.untitledNode }}</span
                                >
                                <span
                                    class="shrink-0 rounded-full border border-slate-700 bg-slate-800 px-2 py-0.5 text-[10px] text-slate-400"
                                    >{{ result.space.name }}</span
                                >
                            </div>
                            <p
                                v-if="result.description"
                                class="truncate text-[11px] text-slate-500"
                            >
                                {{ result.description }}
                            </p>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
