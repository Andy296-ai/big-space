<script setup lang="ts">
import { X, Copy } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { useT, fill } from '../lib/i18n';
import type { NodeData } from './SpaceScene.vue';

const props = defineProps<{
    sourceNode: NodeData;
    allNodes: NodeData[];
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'submit', payload: { parent_id: number | null }): void;
}>();

/** null — валидный выбор («новый независимый корень»), а не «ничего не выбрано». */
const targetParentId = ref<number | null>(null);

const otherNodes = computed(() =>
    props.allNodes.filter((n) => n.id !== props.sourceNode.id),
);

function handleSubmit() {
    emit('submit', { parent_id: targetParentId.value });
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 max-h-[90vh] w-full max-w-md space-y-5 overflow-y-auto rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <Copy class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.copyNodeTitle }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{
                                fill(t.copyNodeSubtitle, {
                                    name:
                                        sourceNode.title || '#' + sourceNode.id,
                                })
                            }}
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

            <div class="text-xs">
                <label class="mb-1.5 block font-semibold text-slate-300">{{
                    t.copyTargetLabel
                }}</label>
                <select
                    v-model="targetParentId"
                    class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                >
                    <option :value="null">
                        {{ t.copyNewRootOption }}
                    </option>
                    <option v-for="n in otherNodes" :key="n.id" :value="n.id">
                        {{ n.title || 'Node #' + n.id }} (ID: #{{ n.id }},
                        Depth: {{ n.depth }})
                    </option>
                </select>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 pt-2"
            >
                <button
                    @click="emit('close')"
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    @click="handleSubmit"
                    class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-lg hover:bg-blue-500"
                >
                    {{ t.copyAction }}
                </button>
            </div>
        </div>
    </div>
</template>
