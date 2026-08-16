<script setup lang="ts">
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    ArrowRight,
    Check,
    ChevronDown,
    ChevronUp,
    ChevronsDown,
    ChevronsUp,
    Copy,
    Download,
    Edit3,
    Eye,
    GitBranch,
    History,
    KeyRound,
    Layers,
    Link,
    Loader2,
    Lock,
    MapPin,
    MessageSquare,
    MessagesSquare,
    Plus,
    Settings2,
    Share2,
    Sparkles,
    Tag,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { apiFetch } from '../lib/api';
import { fill, useT } from '../lib/i18n';
import AttachmentViewer from './AttachmentViewer.vue';
import NodeCommentsModal from './NodeCommentsModal.vue';
import NodeHistoryModal from './NodeHistoryModal.vue';
import ShareNodeModal from './ShareNodeModal.vue';
import type { AttachmentData, NodeData } from './SpaceScene.vue';
import SpaceStructureViewer from './SpaceStructureViewer.vue';
import TreeSettingsModal from './TreeSettingsModal.vue';

const props = defineProps<{
    spaceId: number;
    node: NodeData | null;
    parentNodes: NodeData[];
    childNodes: NodeData[];
    canEdit: boolean;
    canModerateComments: boolean;
    /** Владелец пространства или root — тот же охват, что и у shares-роутов (can:manage,space). */
    canManage: boolean;
    currentUserId: number;
    /** Живой сигнал node.lock.changed, см. Welcome.vue — null, если узел свободен или блокировку держит сам зритель. */
    lockedBy: { id: number; name: string } | null;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'focus-node', nodeId: number): void;
    (e: 'open-add-child'): void;
    (e: 'open-edit'): void;
    (e: 'open-link'): void;
    (e: 'open-copy'): void;
    (e: 'open-discuss', nodeId: number): void;
    (e: 'open-reset-password'): void;
    (e: 'node-restored', node: NodeData): void;
    (e: 'tree-settings-updated', node: NodeData): void;
    (e: 'delete', nodeId: number): void;
}>();

// Сколько показываем до нажатия «показать все».
const TAGS_SHOWN = 4;
const FILES_SHOWN = 5;

const expanded = ref(false);
const showStructureViewer = ref(false);
const showHistory = ref(false);
const showComments = ref(false);
const showTreeSettings = ref(false);
const showShareBranch = ref(false);

interface LinkSuggestion {
    id: number;
    title: string;
    distance: number;
}

const suggestedLinksOpen = ref(false);
const suggestedLinks = ref<LinkSuggestion[]>([]);
const suggestedLinksLoading = ref(false);
const suggestedLinksLoaded = ref(false);

// При переходе на другой узел список снова сворачивается.
watch(
    () => props.node?.id,
    (nodeId) => {
        expanded.value = false;
        showStructureViewer.value = false;
        showHistory.value = false;
        showComments.value = false;
        showTreeSettings.value = false;
        showShareBranch.value = false;
        suggestedLinksOpen.value = false;
        suggestedLinks.value = [];
        suggestedLinksLoaded.value = false;

        // Сигнал «карточка узла открыта» — для журнала аудита. Fire-and-forget:
        // не блокирует открытие карточки, не показывает ошибку пользователю.
        if (nodeId != null) {
            apiFetch(`/api/spaces/${props.spaceId}/nodes/${nodeId}/viewed`, {
                method: 'POST',
            }).catch(() => {});
        }
    },
);

const tags = computed(() =>
    (props.node?.tags ?? '')
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean),
);

const visibleTags = computed(() =>
    expanded.value ? tags.value : tags.value.slice(0, TAGS_SHOWN),
);

const attachments = computed(() => props.node?.attachments ?? []);

/** Подгружается один раз при первом раскрытии секции, не при каждом открытии карточки узла. */
async function loadSuggestedLinks() {
    if (
        !props.node ||
        suggestedLinksLoaded.value ||
        suggestedLinksLoading.value
    ) {
        return;
    }

    suggestedLinksLoading.value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/suggested-links`,
        );
        suggestedLinks.value = await res.json();
        suggestedLinksLoaded.value = true;
    } catch (err) {
        console.error('Failed to load suggested links:', err);
    } finally {
        suggestedLinksLoading.value = false;
    }
}

function toggleSuggestedLinks() {
    suggestedLinksOpen.value = !suggestedLinksOpen.value;

    if (suggestedLinksOpen.value) {
        loadSuggestedLinks();
    }
}

/**
 * Узнанная пара становится ребёнком текущего узла — фиксированное
 * направление ради одного клика без отдельной модалки; при желании
 * обратного направления связь всегда можно перевязать через обычный
 * LinkNodeDialog. Реальную структурную проверку (single_parent/cycle/
 * level_gap) всё равно делает сам GraphController::link() — сюда долетает
 * уже готовое решение, а не догадка клиента.
 */
async function acceptSuggestion(suggestion: LinkSuggestion) {
    if (!props.node) {
        return;
    }

    try {
        const res = await apiFetch(`/api/spaces/${props.spaceId}/links`, {
            method: 'POST',
            body: JSON.stringify({
                parent_id: props.node.id,
                child_id: suggestion.id,
            }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            alert(describeLinkError(data));

            return;
        }

        suggestedLinks.value = suggestedLinks.value.filter(
            (s) => s.id !== suggestion.id,
        );
    } catch (err) {
        console.error('Failed to accept a suggested link:', err);
    }
}

async function dismissSuggestion(suggestion: LinkSuggestion) {
    if (!props.node) {
        return;
    }

    // Оптимистично — сама подсказка низкоставочная, а секунда задержки на
    // отклик сети ради этого не стоит подвисающей кнопки.
    suggestedLinks.value = suggestedLinks.value.filter(
        (s) => s.id !== suggestion.id,
    );

    try {
        await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/suggested-links/${suggestion.id}/dismiss`,
            { method: 'POST' },
        );
    } catch (err) {
        console.error('Failed to dismiss a suggested link:', err);
    }
}

function describeLinkError(data: {
    reason?: string;
    error?: string;
    message?: string;
}): string {
    switch (data.reason) {
        case 'self_link':
            return t.value.linkErrSelf;
        case 'single_parent':
            return t.value.linkErrSingleParent;
        case 'cycle':
            return t.value.linkErrCycle;
        case 'level_gap':
            return t.value.linkErrLevelGap;
        default:
            return data.error ?? data.message ?? 'Request failed.';
    }
}

/** Загруженный файл отдаётся своим маршрутом за авторизацией, ссылка — как есть. */
function hrefFor(item: AttachmentData): string {
    return item.stored
        ? `/api/spaces/${props.spaceId}/nodes/${props.node?.id}/attachments/${item.id}/download`
        : (item.url ?? '#');
}

/** Вложение, открытое в полноэкранном просмотрщике — пока null, вьюер не рендерится. */
const previewAttachment = ref<AttachmentData | null>(null);

const visibleAttachments = computed(() =>
    expanded.value
        ? attachments.value
        : attachments.value.slice(0, FILES_SHOWN),
);

const hasHidden = computed(
    () =>
        tags.value.length > TAGS_SHOWN ||
        attachments.value.length > FILES_SHOWN,
);

const hasMap = computed(
    () => props.node?.map_lat != null && props.node?.map_lon != null,
);

/** Ссылка на полноценную карту OpenStreetMap с той же точкой. */
const mapLink = computed(() => {
    const lat = props.node?.map_lat;
    const lon = props.node?.map_lon;

    if (lat == null || lon == null) {
        return '#';
    }

    return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=16/${lat}/${lon}`;
});

const mapCoords = computed(
    () =>
        `${(props.node?.map_lat ?? 0).toFixed(4)}, ${(props.node?.map_lon ?? 0).toFixed(4)}`,
);

/** Контейнер встроенной карты (Leaflet рисует в него сам, вне реактивности Vue). */
const mapContainer = ref<HTMLDivElement | null>(null);
let leafletMap: L.Map | null = null;
let leafletMarker: L.Marker | null = null;

/** Метка в виде булавки — без внешних картинок, чтобы не завязываться на пути ассетов Leaflet. */
const pinIcon = L.divIcon({
    className: '',
    html: `<svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 22s8-7.58 8-13A8 8 0 1 0 4 9c0 5.42 8 13 8 13Z" fill="#34d399" stroke="#022c22" stroke-width="1.2"/>
        <circle cx="12" cy="9" r="3" fill="#022c22"/>
    </svg>`,
    iconSize: [26, 26],
    iconAnchor: [13, 24],
});

function renderMap() {
    const lat = props.node?.map_lat;
    const lon = props.node?.map_lon;

    if (lat == null || lon == null || !mapContainer.value) {
        return;
    }

    if (!leafletMap) {
        leafletMap = L.map(mapContainer.value).setView([lat, lon], 14);
        leafletMap.attributionControl.setPrefix(false);

        L.tileLayer(
            'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
                maxZoom: 20,
            },
        ).addTo(leafletMap);

        leafletMarker = L.marker([lat, lon], { icon: pinIcon }).addTo(
            leafletMap,
        );
    } else {
        leafletMap.setView([lat, lon], 14);
        leafletMarker?.setLatLng([lat, lon]);
        leafletMap.invalidateSize();
    }
}

function destroyMap() {
    leafletMap?.remove();
    leafletMap = null;
    leafletMarker = null;
}

/** Пересоздаём/обновляем карту при смене узла или его точки — контейнер живёт, пока hasMap истинно. */
watch(
    () => [props.node?.id, props.node?.map_lat, props.node?.map_lon] as const,
    async () => {
        await nextTick();

        if (hasMap.value) {
            renderMap();
        } else {
            destroyMap();
        }
    },
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(() => destroyMap());
</script>

<template>
    <div
        v-if="node"
        class="animate-in fade-in slide-in-from-bottom-4 absolute bottom-6 left-6 z-20 flex max-h-[calc(100vh-9rem)] w-80 flex-col rounded-3xl border border-slate-700/80 bg-slate-900/90 text-slate-100 shadow-2xl backdrop-blur-xl duration-200 max-md:fixed max-md:inset-x-0 max-md:top-0 max-md:bottom-auto max-md:z-30 max-md:h-dvh max-md:max-h-none max-md:w-full max-md:max-w-none max-md:rounded-none sm:w-96"
    >
        <!-- Шапка -->
        <div class="flex items-start justify-between gap-3 p-5 pb-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <div
                    class="h-4 w-4 shrink-0 rounded-full border border-white/20 shadow-md"
                    :style="{ backgroundColor: node.color || '#3b82f6' }"
                />
                <div class="min-w-0">
                    <h3 class="line-clamp-1 text-base font-bold">
                        {{ node.title || t.untitledNode }}
                    </h3>
                    <span class="text-xs text-slate-400"
                        >ID: #{{ node.id }}</span
                    >
                </div>
            </div>
            <button
                @click="emit('close')"
                :aria-label="t.close"
                class="shrink-0 rounded-xl p-1 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
            >
                <X class="h-4 w-4" />
            </button>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5">
            <!-- Описание -->
            <p
                v-if="node.description"
                class="max-h-24 overflow-y-auto rounded-2xl border border-slate-800/80 bg-slate-950/40 p-3 text-xs leading-relaxed text-slate-300"
            >
                {{ node.description }}
            </p>

            <!-- Глубина и координаты -->
            <div class="grid grid-cols-2 gap-2 text-xs">
                <div
                    class="flex items-center gap-2 rounded-xl border border-slate-700/40 bg-slate-800/50 p-2.5"
                >
                    <Layers class="h-3.5 w-3.5 shrink-0 text-blue-400" />
                    <div class="min-w-0">
                        <div class="text-[10px] text-slate-400">
                            {{ t.depthLabel }}
                        </div>
                        <div class="font-semibold">{{ node.depth }}</div>
                    </div>
                </div>
                <div
                    class="flex items-center gap-2 rounded-xl border border-slate-700/40 bg-slate-800/50 p-2.5"
                >
                    <MapPin class="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                    <div class="min-w-0">
                        <div class="text-[10px] text-slate-400">
                            {{ t.coordinatesLabel }}
                        </div>
                        <div class="truncate font-semibold">
                            {{ Math.round(node.pos_x) }},
                            {{ Math.round(node.pos_y) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Теги: только если есть -->
            <div v-if="tags.length" class="flex flex-wrap items-center gap-1.5">
                <span
                    v-for="tag in visibleTags"
                    :key="tag"
                    class="inline-flex items-center gap-1 rounded-full border border-slate-700 bg-slate-800 px-2.5 py-0.5 text-[10px] text-slate-300"
                >
                    <Tag class="h-3 w-3 text-slate-400" />
                    {{ tag }}
                </span>
                <span
                    v-if="!expanded && tags.length > TAGS_SHOWN"
                    class="px-1 text-xs font-bold text-slate-500"
                    >…</span
                >
            </div>

            <!-- Связи: родители/дети, клик переключает панель на них -->
            <div
                v-if="parentNodes.length || childNodes.length"
                class="space-y-2"
            >
                <div
                    v-if="parentNodes.length"
                    class="rounded-xl border border-slate-700/40 bg-slate-800/50 p-2.5"
                >
                    <div
                        class="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                    >
                        <ChevronsUp class="h-3 w-3" />
                        <span
                            >{{ t.parentsLabel }} ({{
                                parentNodes.length
                            }})</span
                        >
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="p in parentNodes"
                            :key="p.id"
                            type="button"
                            @click="emit('focus-node', p.id)"
                            class="flex items-center gap-1.5 rounded-full border border-slate-700 bg-slate-900/60 px-2.5 py-1 text-[11px] text-slate-200 transition-colors hover:border-blue-500 hover:text-blue-300"
                        >
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: p.color || '#3b82f6',
                                }"
                            />
                            <span class="max-w-[9rem] truncate">{{
                                p.title || t.untitledNode
                            }}</span>
                        </button>
                    </div>
                </div>

                <div
                    v-if="childNodes.length"
                    class="rounded-xl border border-slate-700/40 bg-slate-800/50 p-2.5"
                >
                    <div
                        class="mb-1.5 flex items-center gap-1.5 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                    >
                        <ChevronsDown class="h-3 w-3" />
                        <span
                            >{{ t.childrenLabel }} ({{
                                childNodes.length
                            }})</span
                        >
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="c in childNodes"
                            :key="c.id"
                            type="button"
                            @click="emit('focus-node', c.id)"
                            class="flex items-center gap-1.5 rounded-full border border-slate-700 bg-slate-900/60 px-2.5 py-1 text-[11px] text-slate-200 transition-colors hover:border-blue-500 hover:text-blue-300"
                        >
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: c.color || '#3b82f6',
                                }"
                            />
                            <span class="max-w-[9rem] truncate">{{
                                c.title || t.untitledNode
                            }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Подсказки связей по смыслу — свёрнуто, грузится при первом раскрытии. Доступно только editor'ам, как и само связывание. -->
            <div
                v-if="canEdit"
                class="rounded-xl border border-slate-700/40 bg-slate-800/50 p-2.5"
            >
                <button
                    type="button"
                    @click="toggleSuggestedLinks"
                    class="flex w-full items-center gap-1.5 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                >
                    <Sparkles class="h-3 w-3 text-violet-400" />
                    <span class="flex-1 text-start">{{
                        t.suggestedLinksLabel
                    }}</span>
                    <ChevronDown v-if="!suggestedLinksOpen" class="h-3 w-3" />
                    <ChevronUp v-else class="h-3 w-3" />
                </button>

                <div v-if="suggestedLinksOpen" class="mt-1.5">
                    <div
                        v-if="suggestedLinksLoading"
                        class="flex items-center gap-1.5 py-1 text-[11px] text-slate-500"
                    >
                        <Loader2 class="h-3 w-3 animate-spin" />
                        {{ t.sharedLoadingLabel }}
                    </div>
                    <p
                        v-else-if="!suggestedLinks.length"
                        class="py-1 text-[11px] text-slate-500"
                    >
                        {{ t.suggestedLinksEmpty }}
                    </p>
                    <div v-else class="flex flex-col gap-1">
                        <div
                            v-for="s in suggestedLinks"
                            :key="s.id"
                            class="flex items-center gap-1.5 rounded-full border border-violet-700/40 bg-violet-500/5 py-1 ps-2.5 pe-1 text-[11px] text-slate-200"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                s.title || t.untitledNode
                            }}</span>
                            <button
                                type="button"
                                @click="acceptSuggestion(s)"
                                :aria-label="t.acceptSuggestionAction"
                                :title="t.acceptSuggestionAction"
                                class="shrink-0 rounded-full p-1 text-emerald-400 hover:bg-emerald-500/10"
                            >
                                <Check class="h-3 w-3" />
                            </button>
                            <button
                                type="button"
                                @click="dismissSuggestion(s)"
                                :aria-label="t.dismissSuggestionAction"
                                :title="t.dismissSuggestionAction"
                                class="shrink-0 rounded-full p-1 text-slate-400 hover:bg-slate-700"
                            >
                                <X class="h-3 w-3" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Карта: только если у узла задана точка -->
            <div v-if="hasMap">
                <div
                    ref="mapContainer"
                    class="h-40 overflow-hidden rounded-2xl border border-slate-700/60 bg-slate-800/50"
                />
                <div
                    class="mt-1.5 flex items-center justify-between gap-2 px-1 text-[11px]"
                >
                    <span class="truncate font-mono text-slate-400">{{
                        mapCoords
                    }}</span>
                    <a
                        :href="mapLink"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shrink-0 font-semibold text-blue-400 hover:underline"
                    >
                        {{ t.openInMaps }}
                    </a>
                </div>
                <div
                    v-if="node.map_title"
                    class="mt-0.5 px-1 text-[11px] text-slate-400"
                >
                    {{ node.map_title }}
                </div>
            </div>

            <!-- Структура связанного пространства: только у узлов-пространств в Admin -->
            <button
                v-if="node.linked_space_id"
                type="button"
                @click="showStructureViewer = true"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <GitBranch class="h-3.5 w-3.5 text-emerald-400" />
                <span>{{ t.viewStructureAction }}</span>
            </button>

            <!-- Сброс пароля: только у узлов-пользователей в Admin -->
            <button
                v-if="node.linked_user_id"
                type="button"
                @click="emit('open-reset-password')"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <KeyRound class="h-3.5 w-3.5 text-amber-400" />
                <span>{{ t.resetPasswordAction }}</span>
            </button>

            <!-- История правок: видна всем с доступом, восстановление — только у editor/owner (проверяется на сервере) -->
            <button
                type="button"
                @click="showHistory = true"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <History class="h-3.5 w-3.5 text-indigo-400" />
                <span>{{ t.nodeHistoryAction }}</span>
            </button>

            <!-- Обсуждение: доступно всем с доступом к пространству, включая viewer -->
            <button
                type="button"
                @click="showComments = true"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <MessageSquare class="h-3.5 w-3.5 text-blue-400" />
                <span>{{ t.commentsAction }}</span>
            </button>

            <!-- Тред в мессенджере, привязанный к узлу — та же видимость, что у комментариев -->
            <button
                type="button"
                @click="emit('open-discuss', node.id)"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <MessagesSquare class="h-3.5 w-3.5 text-emerald-400" />
                <span>{{ t.messengerDiscussAction }}</span>
            </button>

            <!-- Настройки дерева: только у корневых узлов, и только если можно менять граф -->
            <button
                v-if="node.depth === 0 && canEdit"
                type="button"
                @click="showTreeSettings = true"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <Settings2 class="h-3.5 w-3.5 text-violet-400" />
                <span>{{ t.treeSettingsAction }}</span>
            </button>

            <!-- Файлы и ссылки: только если прикреплены -->
            <div v-if="attachments.length">
                <div
                    class="mb-1.5 px-1 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                >
                    {{ t.attachmentsLabel }}
                </div>
                <div
                    class="divide-y divide-slate-700/50 overflow-hidden rounded-2xl border border-slate-700/60 bg-slate-800/50"
                >
                    <div
                        v-for="item in visibleAttachments"
                        :key="item.id"
                        class="flex items-center gap-2 px-3 py-2 text-xs transition-colors hover:bg-slate-800"
                    >
                        <span class="min-w-0 flex-1 truncate text-slate-200">
                            {{ item.label || item.url }}
                        </span>
                        <button
                            v-if="item.previewable"
                            type="button"
                            @click="previewAttachment = item"
                            :aria-label="t.previewAction"
                            :title="t.previewAction"
                            class="shrink-0 rounded p-0.5 text-slate-400 transition-colors hover:text-blue-400"
                        >
                            <Eye class="h-3.5 w-3.5" />
                        </button>
                        <a
                            :href="hrefFor(item)"
                            :download="item.kind === 'file' ? '' : undefined"
                            :target="
                                item.kind === 'link' ? '_blank' : undefined
                            "
                            rel="noopener noreferrer"
                            :aria-label="
                                item.kind === 'file' ? t.download : t.linkNode
                            "
                            :title="
                                item.kind === 'file' ? t.download : t.linkNode
                            "
                            class="shrink-0 rounded p-0.5 text-slate-400 transition-colors hover:text-blue-400"
                        >
                            <Download
                                v-if="item.kind === 'file'"
                                class="h-3.5 w-3.5"
                            />
                            <ArrowRight v-else class="h-3.5 w-3.5" />
                        </a>
                        <span
                            v-if="item.badge"
                            class="shrink-0 border-s border-slate-600 ps-2 text-[10px] font-bold text-slate-400"
                        >
                            {{ item.badge }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Развернуть скрытые теги и файлы -->
            <button
                v-if="hasHidden"
                type="button"
                @click="expanded = !expanded"
                class="flex w-full items-center justify-center gap-1.5 rounded-xl py-1 text-[11px] font-semibold text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
            >
                <span>{{ expanded ? t.collapseLabel : t.showAllLabel }}</span>
                <ChevronUp v-if="expanded" class="h-3.5 w-3.5" />
                <ChevronDown v-else class="h-3.5 w-3.5" />
            </button>
        </div>

        <!-- Живой бейдж «редактируется сейчас» — только когда держит НЕ сам зритель. -->
        <div
            v-if="canEdit && lockedBy && lockedBy.id !== currentUserId"
            class="mx-5 mt-3 flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-600/10 px-3 py-2 text-[11px] font-semibold text-amber-300"
        >
            <Lock class="h-3.5 w-3.5 shrink-0" />
            <span>{{ fill(t.nodeLockBadge, { name: lockedBy.name }) }}</span>
        </div>

        <!-- Действия: только если есть право менять граф -->
        <div
            v-if="canEdit"
            class="grid grid-cols-2 gap-2 border-t border-slate-800 p-5 pt-3"
        >
            <button
                @click="emit('open-add-child')"
                class="flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow transition-all hover:bg-blue-500 active:scale-95"
            >
                <Plus class="h-3.5 w-3.5" />
                <span>{{ t.addChild }}</span>
            </button>

            <button
                @click="emit('open-link')"
                class="flex items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 transition-all hover:bg-slate-700 active:scale-95"
            >
                <Link class="h-3.5 w-3.5 text-blue-400" />
                <span>{{ t.linkNode }}</span>
            </button>

            <button
                @click="emit('open-edit')"
                class="flex items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 transition-all hover:bg-slate-700 active:scale-95"
            >
                <Edit3 class="h-3.5 w-3.5 text-amber-400" />
                <span>{{ t.editNode }}</span>
            </button>

            <button
                @click="emit('open-copy')"
                class="flex items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 transition-all hover:bg-slate-700 active:scale-95"
            >
                <Copy class="h-3.5 w-3.5 text-emerald-400" />
                <span>{{ t.copyNode }}</span>
            </button>

            <button
                v-if="canManage"
                @click="showShareBranch = true"
                class="col-span-2 flex items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 transition-all hover:bg-slate-700 active:scale-95"
            >
                <Share2 class="h-3.5 w-3.5 text-sky-400" />
                <span>{{ t.shareBranchAction }}</span>
            </button>

            <button
                @click="emit('delete', node.id)"
                class="col-span-2 flex items-center justify-center gap-1.5 rounded-xl border border-rose-800/60 bg-rose-950/40 px-3 py-2 text-xs font-semibold text-rose-300 transition-all hover:bg-rose-900/60 active:scale-95"
            >
                <Trash2 class="h-3.5 w-3.5" />
                <span>{{ t.deleteAction }}</span>
            </button>
        </div>
    </div>

    <AttachmentViewer
        v-if="previewAttachment && node"
        :space-id="spaceId"
        :node-id="node.id"
        :attachment="previewAttachment"
        @close="previewAttachment = null"
    />

    <SpaceStructureViewer
        v-if="showStructureViewer && node?.linked_space_id"
        :space-id="node.linked_space_id"
        :title="node.title || t.untitledNode"
        @close="showStructureViewer = false"
    />

    <NodeHistoryModal
        v-if="showHistory && node"
        :space-id="spaceId"
        :node="node"
        :can-restore="canEdit"
        @close="showHistory = false"
        @restored="
            (updated) => {
                showHistory = false;
                emit('node-restored', updated);
            }
        "
    />

    <NodeCommentsModal
        v-if="showComments && node"
        :space-id="spaceId"
        :node="node"
        :current-user-id="currentUserId"
        :can-moderate="canModerateComments"
        @close="showComments = false"
    />

    <TreeSettingsModal
        v-if="showTreeSettings && node"
        :space-id="spaceId"
        :node="node"
        @close="showTreeSettings = false"
        @updated="
            (updated) => {
                showTreeSettings = false;
                emit('tree-settings-updated', updated);
            }
        "
    />

    <ShareNodeModal
        v-if="showShareBranch && node"
        :space-id="spaceId"
        :node="node"
        @close="showShareBranch = false"
    />
</template>
