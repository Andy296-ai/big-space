<script setup lang="ts">
import { AlertOctagon, Trash2, X } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';
import { apiFetch } from '../lib/api';
import { useT, fill } from '../lib/i18n';
import type { NodeData } from './SpaceScene.vue';

const props = defineProps<{
    node: NodeData;
    spaceId: number;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', deletionIds: number[]): void;
}>();

const isLoading = ref(true);
const deletionIds = ref<number[]>([]);

onMounted(async () => {
    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/deletion-preview`,
        );
        const data = await res.json();
        deletionIds.value = data.deletion_ids || [props.node.id];
    } catch {
        deletionIds.value = [props.node.id];
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
    >
        <div
            class="animate-in fade-in zoom-in-95 w-full max-w-md space-y-5 rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-rose-500/30 bg-rose-600/20 p-2 text-rose-400"
                    >
                        <AlertOctagon class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.deleteNodeTitle }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{ node.title || t.untitledNode }}
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
                v-if="isLoading"
                class="py-6 text-center text-xs text-slate-400"
            >
                {{ t.calculatingAffected }}
            </div>

            <div v-else class="space-y-3 text-xs text-slate-300">
                <p>
                    {{
                        fill(t.deleteConfirmQuestion, {
                            title: node.title || t.untitledNode,
                        })
                    }}
                </p>

                <div
                    v-if="deletionIds.length > 1"
                    class="space-y-1 rounded-2xl border border-rose-800/50 bg-rose-950/30 p-3 text-rose-200"
                >
                    <div class="text-xs font-bold">
                        {{ t.cascadeNoticeTitle }}
                    </div>
                    <p class="text-[11px] opacity-90">
                        {{
                            fill(t.cascadeNoticeBody, {
                                n: deletionIds.length - 1,
                            })
                        }}
                    </p>
                    <div class="mt-1 text-[10px] text-rose-300/70">
                        {{ fill(t.totalToDelete, { n: deletionIds.length }) }}
                    </div>
                </div>

                <p class="text-[11px] text-slate-400">
                    {{ t.undoHint }}
                </p>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 pt-2"
            >
                <button
                    @click="emit('close')"
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    :disabled="isLoading"
                    @click="emit('confirm', deletionIds)"
                    class="flex items-center gap-1.5 rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-lg hover:bg-rose-500 disabled:opacity-50"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                    <span>{{ t.confirmDelete }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
