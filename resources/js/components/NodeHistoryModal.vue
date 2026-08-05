<script setup lang="ts">
import { History, Loader2, RotateCcw, X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { NodeData } from './SpaceScene.vue';

interface RevisionEntry {
    id: number;
    title: string;
    description: string | null;
    created_at: string;
    editor: { id: number; name: string } | null;
}

const props = defineProps<{
    spaceId: number;
    node: NodeData;
    canRestore: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'restored', node: NodeData): void;
}>();

const t = useT();

const loading = ref(true);
const revisions = ref<RevisionEntry[]>([]);
const restoringId = ref<number | null>(null);

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/revisions`,
        );
        revisions.value = await res.json();
    } catch (err) {
        console.error('Failed to load node history:', err);
    } finally {
        loading.value = false;
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

async function restore(revision: RevisionEntry) {
    if (restoringId.value !== null) {
        return;
    }

    restoringId.value = revision.id;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/revisions/${revision.id}/restore`,
            { method: 'POST' },
        );
        const updated = await res.json();
        emit('restored', updated);
    } catch (err) {
        console.error('Failed to restore revision:', err);
    } finally {
        restoringId.value = null;
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
                class="flex h-[75vh] w-[92vw] max-w-lg flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3.5"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div
                            class="rounded-xl border border-indigo-500/30 bg-indigo-600/20 p-2 text-indigo-400"
                        >
                            <History class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold">
                                {{ t.nodeHistoryTitle }}
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

                <div class="min-h-0 flex-1 overflow-auto p-3">
                    <div
                        v-if="loading"
                        class="flex h-full items-center justify-center text-slate-400"
                    >
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </div>
                    <div
                        v-else-if="!revisions.length"
                        class="flex h-full items-center justify-center text-xs text-slate-500"
                    >
                        {{ t.nodeHistoryEmpty }}
                    </div>
                    <div v-else class="space-y-1.5">
                        <div
                            v-for="revision in revisions"
                            :key="revision.id"
                            class="flex items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-800/40 p-3"
                        >
                            <div class="min-w-0 flex-1 text-xs">
                                <p
                                    class="truncate font-semibold text-slate-200"
                                >
                                    {{ revision.title || t.untitledNode }}
                                </p>
                                <p class="mt-0.5 text-[10px] text-slate-500">
                                    {{ formatTime(revision.created_at) }}
                                    <template v-if="revision.editor">
                                        · {{ revision.editor.name }}
                                    </template>
                                </p>
                            </div>
                            <button
                                v-if="canRestore"
                                :disabled="restoringId !== null"
                                @click="restore(revision)"
                                class="flex shrink-0 items-center gap-1.5 rounded-xl border border-indigo-500/40 bg-indigo-600/20 px-2.5 py-1.5 text-[11px] font-semibold text-indigo-300 transition-colors hover:bg-indigo-600/30 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Loader2
                                    v-if="restoringId === revision.id"
                                    class="h-3.5 w-3.5 animate-spin"
                                />
                                <RotateCcw v-else class="h-3.5 w-3.5" />
                                <span>{{ t.restoreVersionAction }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
