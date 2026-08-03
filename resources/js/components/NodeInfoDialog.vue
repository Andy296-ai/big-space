<script setup lang="ts">
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    ArrowRight,
    ChevronDown,
    ChevronUp,
    Download,
    Edit3,
    Eye,
    Layers,
    Link,
    MapPin,
    Plus,
    Tag,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useT } from '../lib/i18n';
import AttachmentViewer from './AttachmentViewer.vue';
import type { AttachmentData, NodeData } from './SpaceScene.vue';

const props = defineProps<{
    spaceId: number;
    node: NodeData | null;
    childrenCount: number;
    parentsCount: number;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'open-add-child'): void;
    (e: 'open-edit'): void;
    (e: 'open-link'): void;
    (e: 'delete', nodeId: number): void;
}>();

// Сколько показываем до нажатия «показать все».
const TAGS_SHOWN = 4;
const FILES_SHOWN = 5;

const expanded = ref(false);

// При переходе на другой узел список снова сворачивается.
watch(
    () => props.node?.id,
    () => {
        expanded.value = false;
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
        class="animate-in fade-in slide-in-from-bottom-4 absolute bottom-6 left-6 z-20 flex max-h-[calc(100vh-9rem)] w-80 flex-col rounded-3xl border border-slate-700/80 bg-slate-900/90 text-slate-100 shadow-2xl backdrop-blur-xl duration-200 sm:w-96"
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
                            :target="item.kind === 'link' ? '_blank' : undefined"
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

        <!-- Действия -->
        <div class="grid grid-cols-2 gap-2 border-t border-slate-800 p-5 pt-3">
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
                @click="emit('delete', node.id)"
                class="flex items-center justify-center gap-1.5 rounded-xl border border-rose-800/60 bg-rose-950/40 px-3 py-2 text-xs font-semibold text-rose-300 transition-all hover:bg-rose-900/60 active:scale-95"
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
</template>
