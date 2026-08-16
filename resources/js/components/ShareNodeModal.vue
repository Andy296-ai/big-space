<script setup lang="ts">
import { GitBranch, X } from 'lucide-vue-next';
import { useT } from '../lib/i18n';
import PublicLinkManager from './PublicLinkManager.vue';
import type { NodeData } from './SpaceScene.vue';

defineProps<{
    spaceId: number;
    node: NodeData;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
}>();
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 max-h-[90vh] w-full max-w-md space-y-4 overflow-y-auto rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-emerald-500/30 bg-emerald-600/20 p-2 text-emerald-400"
                    >
                        <GitBranch class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.shareBranchAction }}
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

            <p class="text-xs text-slate-400">
                {{ t.publicLinkDesc }}
            </p>

            <PublicLinkManager
                :api-url="`/api/spaces/${spaceId}/nodes/${node.id}/share`"
            />
        </div>
    </div>
</template>
