<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Download,
    Eye,
    File as FileIcon,
    GitBranch,
    Loader2,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import NodusMark from '../components/NodusMark.vue';
import type {
    CameraState,
    EdgeData,
    NodeData,
} from '../components/SpaceScene.vue';
import SpaceScene from '../components/SpaceScene.vue';
import { apiFetch } from '../lib/api';
import { provideSettings, useT } from '../lib/i18n';
import { applyTheme, loadSettings } from '../types/settings';

/**
 * Публичная read-only страница по токену — никакой аутентификации,
 * никакого HUD-редактирования, никакого мессенджера/комментариев (их тут
 * попросту нет ни в одном API-вызове). SpaceScene переиспользуется в уже
 * существующем режиме canEdit=false — драг/подменю правой кнопки там и так
 * выключены этим же флагом для обычных viewer'ов.
 */
const props = defineProps<{
    token: string;
    spaceName: string;
    isSubtree: boolean;
}>();

const settings = ref(loadSettings());
provideSettings(settings);
const t = useT();

const loading = ref(true);
const nodes = ref<NodeData[]>([]);
const edges = ref<EdgeData[]>([]);
const selectedNodeId = ref<number | null>(null);
const hoveredNodeId = ref<number | null>(null);
const cameraState = ref<CameraState | null>(null);

const selectedNode = computed(
    () => nodes.value.find((n) => n.id === selectedNodeId.value) ?? null,
);

function baseUrl(): string {
    return `/api/shared/${props.token}`;
}

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(`${baseUrl()}/graph`);
        const data = await res.json();
        nodes.value = data.nodes ?? [];
        edges.value = data.edges ?? [];
    } catch (err) {
        console.error('Failed to load the shared graph:', err);
    } finally {
        loading.value = false;
    }
}

function downloadUrl(nodeId: number, attachmentId: number): string {
    return `${baseUrl()}/nodes/${nodeId}/attachments/${attachmentId}/download`;
}

function previewUrl(nodeId: number, attachmentId: number): string {
    return `${baseUrl()}/nodes/${nodeId}/attachments/${attachmentId}/preview`;
}

function handleSelectNode(node: NodeData | null) {
    selectedNodeId.value = node?.id ?? null;
}

onMounted(() => {
    applyTheme(settings.value);
    load();
});
</script>

<template>
    <Head :title="spaceName" />

    <div class="relative h-screen w-screen overflow-hidden bg-slate-950">
        <div
            class="pointer-events-none absolute top-4 left-4 z-20 flex items-center gap-3 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-4 py-2.5 text-slate-100 shadow-xl backdrop-blur-md"
        >
            <div
                class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
            >
                <NodusMark class="h-5 w-5" />
            </div>
            <div>
                <div class="text-base font-bold">{{ spaceName }}</div>
                <div
                    class="flex items-center gap-1.5 text-[10.5px] font-medium text-slate-400"
                >
                    <GitBranch
                        v-if="isSubtree"
                        class="h-3 w-3 shrink-0 text-emerald-400"
                    />
                    <span>{{
                        isSubtree ? t.sharedSubtreeBadge : t.sharedReadOnlyBadge
                    }}</span>
                </div>
            </div>
        </div>

        <div
            v-if="loading"
            class="flex h-full items-center justify-center text-slate-400"
        >
            <Loader2 class="h-6 w-6 animate-spin" />
        </div>

        <SpaceScene
            v-else
            :nodes="nodes"
            :edges="edges"
            :selected-node-id="selectedNodeId"
            :hovered-node-id="hoveredNodeId"
            :filtered-node-ids="null"
            :settings="settings"
            :can-edit="false"
            :remote-cursors="[]"
            @select-node="handleSelectNode"
            @hover-node="(n) => (hoveredNodeId = n?.id ?? null)"
            @update-camera="(c) => (cameraState = c)"
        />

        <!-- Карточка узла — минимальная read-only версия NodeInfoDialog: без
             редактирования/комментариев/«обсудить», их для анонима нет и в API. -->
        <div
            v-if="selectedNode"
            class="animate-in fade-in slide-in-from-right-4 pointer-events-auto absolute top-4 right-4 bottom-6 z-20 flex w-96 max-w-[92vw] flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900/95 text-slate-100 shadow-2xl backdrop-blur-xl duration-200"
        >
            <div
                class="flex items-start justify-between gap-3 border-b border-slate-800 p-5"
            >
                <h3 class="min-w-0 flex-1 text-base font-bold break-words">
                    {{ selectedNode.title || t.untitledNode }}
                </h3>
                <button
                    @click="selectedNodeId = null"
                    :aria-label="t.close"
                    class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 text-xs">
                <p
                    v-if="selectedNode.description"
                    class="leading-relaxed whitespace-pre-wrap text-slate-300"
                >
                    {{ selectedNode.description }}
                </p>

                <div v-if="selectedNode.tags" class="flex flex-wrap gap-1.5">
                    <span
                        v-for="tag in selectedNode.tags
                            .split(',')
                            .map((s) => s.trim())
                            .filter(Boolean)"
                        :key="tag"
                        class="rounded-full border border-slate-700 bg-slate-800 px-2 py-0.5 text-[10.5px] text-slate-300"
                    >
                        {{ tag }}
                    </span>
                </div>

                <div
                    v-if="selectedNode.attachments?.length"
                    class="space-y-1.5"
                >
                    <div
                        class="text-[10.5px] font-bold tracking-wider text-slate-500 uppercase"
                    >
                        {{ t.attachmentsLabel }}
                    </div>
                    <div
                        v-for="attachment in selectedNode.attachments"
                        :key="attachment.id"
                        class="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-800/40 p-2.5"
                    >
                        <FileIcon class="h-4 w-4 shrink-0 text-slate-400" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-200">
                                {{ attachment.label }}
                            </div>
                            <div class="text-[10px] text-slate-500">
                                {{ attachment.badge }}
                            </div>
                        </div>
                        <a
                            v-if="attachment.previewable"
                            :href="previewUrl(selectedNode.id, attachment.id)"
                            target="_blank"
                            rel="noopener"
                            :aria-label="t.previewAction"
                            :title="t.previewAction"
                            class="shrink-0 rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-700 hover:text-slate-200"
                        >
                            <Eye class="h-3.5 w-3.5" />
                        </a>
                        <a
                            v-if="attachment.stored"
                            :href="downloadUrl(selectedNode.id, attachment.id)"
                            :aria-label="t.download"
                            :title="t.download"
                            class="shrink-0 rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-700 hover:text-slate-200"
                        >
                            <Download class="h-3.5 w-3.5" />
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else-if="!loading"
            class="pointer-events-none absolute bottom-6 left-1/2 z-20 -translate-x-1/2 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-4 py-2 text-[11px] text-slate-400 backdrop-blur-md"
        >
            {{ t.sharedSelectNodeHint }}
        </div>
    </div>
</template>
