<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';

import AddNodeModal from '../components/AddNodeModal.vue';
import type { PendingAttachment } from '../components/AttachmentEditor.vue';
import CopyNodeModal from '../components/CopyNodeModal.vue';
import DeleteConfirmModal from '../components/DeleteConfirmModal.vue';
import EditNodeModal from '../components/EditNodeModal.vue';
import HudOverlay from '../components/HudOverlay.vue';
import LinkNodeDialog from '../components/LinkNodeDialog.vue';
import Minimap from '../components/Minimap.vue';
import NodeInfoDialog from '../components/NodeInfoDialog.vue';
import SettingsPanel from '../components/SettingsPanel.vue';
import SpaceChooserModal from '../components/SpaceChooserModal.vue';
import type { SpaceItem } from '../components/SpaceChooserModal.vue';
import type {
    CameraState,
    EdgeData,
    NodeData,
    NodeShape,
} from '../components/SpaceScene.vue';
import SpaceScene from '../components/SpaceScene.vue';
import { apiFetch } from '../lib/api';
import { provideSettings } from '../lib/i18n';
import {
    applyTheme,
    loadSettings,
    saveSettings,
    translations,
} from '../types/settings';
import type { AppSettings, SpaceStructure } from '../types/settings';
import { computeLayout } from '../utils/layoutEngine';

const props = defineProps<{
    currentSpace: SpaceItem;
    spaces: SpaceItem[];
}>();

const sceneRef = ref<InstanceType<typeof SpaceScene> | null>(null);
const hudRef = ref<InstanceType<typeof HudOverlay> | null>(null);

const nodes = ref<NodeData[]>([]);
const edges = ref<EdgeData[]>([]);

const selectedNode = ref<NodeData | null>(null);
const hoveredNode = ref<NodeData | null>(null);

// Modal states
const showSpaceModal = ref(false);
const showSettingsModal = ref(false);
const showAddModal = ref(false);
const showEditModal = ref(false);
const showLinkModal = ref(false);
const showCopyModal = ref(false);
const showDeleteModal = ref(false);
const addModalParentNode = ref<NodeData | null>(null);

// Снимок удалённого хранится на сервере — здесь только одноразовый токен на него.
const undoToken = ref<string | null>(null);

// Filters
const filterState = ref<{
    searchQuery: string;
    maxDepth: number | null;
    leavesOnly: boolean;
    tagQuery: string;
    createdFrom: string;
    createdTo: string;
}>({
    searchQuery: '',
    maxDepth: null,
    leavesOnly: false,
    tagQuery: '',
    createdFrom: '',
    createdTo: '',
});

// Узлы, у которых запрос нашёлся внутри содержимого md/txt-вложения — считает
// сервер, потому что само содержимое на фронт не грузится. Обновляется с
// задержкой, чтобы не дёргать эндпоинт на каждое нажатие клавиши.
const contentMatchIds = ref<Set<number>>(new Set());
let contentSearchTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => filterState.value.searchQuery,
    (query) => {
        if (contentSearchTimer) {
            clearTimeout(contentSearchTimer);
        }

        const trimmed = query.trim();

        if (trimmed.length < 2) {
            contentMatchIds.value = new Set();

            return;
        }

        contentSearchTimer = setTimeout(async () => {
            try {
                const res = await apiFetch(
                    `/api/spaces/${props.currentSpace.id}/attachments/search?q=${encodeURIComponent(trimmed)}`,
                );
                const data = await res.json();
                contentMatchIds.value = new Set(data.node_ids ?? []);
            } catch (err) {
                console.error('Attachment content search failed:', err);
            }
        }, 300);
    },
);

const cameraState = ref<CameraState>({
    x: 0,
    y: 0,
    scale: 1,
    view: { w: 1000, h: 700 },
    bounds: { minX: -300, maxX: 300, minY: -300, maxY: 300 },
});

const appSettings = ref<AppSettings>(loadSettings());

// Язык доступен всем вложенным модалкам без прокидывания пропов.
provideSettings(appSettings);

// Структура — свойство пространства на сервере, а не пользовательская настройка.
const spaceStructure = ref<SpaceStructure>(props.currentSpace.structure);
const structureError = ref<string | null>(null);

watch(
    appSettings,
    (s) => {
        saveSettings(s);
        applyTheme(s);
    },
    { deep: true },
);

const filteredNodeIds = computed<Set<number> | null>(() => {
    const { searchQuery, maxDepth, leavesOnly, tagQuery, createdFrom, createdTo } =
        filterState.value;
    const isFiltering =
        searchQuery ||
        maxDepth !== null ||
        leavesOnly ||
        tagQuery ||
        createdFrom ||
        createdTo;

    if (!isFiltering) {
        return null;
    }

    const parentSet = new Set(edges.value.map((e) => e.parent_id));

    // Границы дня: "с" включает его начало, "по" — весь день целиком.
    const fromDate = createdFrom ? new Date(`${createdFrom}T00:00:00`) : null;
    const toDate = createdTo ? new Date(`${createdTo}T23:59:59.999`) : null;

    const validIds = new Set<number>();
    nodes.value.forEach((n) => {
        if (maxDepth !== null && n.depth > maxDepth) {
            return;
        }

        if (leavesOnly && parentSet.has(n.id)) {
            return;
        }

        if (tagQuery) {
            const q = tagQuery.toLowerCase();

            if (!n.tags || !n.tags.toLowerCase().includes(q)) {
                return;
            }
        }

        if (fromDate || toDate) {
            const createdAt = new Date(n.created_at);

            if (fromDate && createdAt < fromDate) {
                return;
            }

            if (toDate && createdAt > toDate) {
                return;
            }
        }

        if (searchQuery) {
            const q = searchQuery.toLowerCase();
            const attachmentLabelMatch = (n.attachments ?? []).some((a) =>
                a.label?.toLowerCase().includes(q),
            );
            const match =
                (n.title && n.title.toLowerCase().includes(q)) ||
                (n.description && n.description.toLowerCase().includes(q)) ||
                (n.tags && n.tags.toLowerCase().includes(q)) ||
                attachmentLabelMatch ||
                contentMatchIds.value.has(n.id);

            if (!match) {
                return;
            }
        }

        validIds.add(n.id);
    });

    return validIds;
});

// Fetch graph data for current space
async function loadGraph() {
    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/graph`,
        );
        const data = await res.json();
        nodes.value = data.nodes || [];
        edges.value = data.edges || [];
    } catch (err) {
        console.error('Failed to load graph:', err);
    }
}

onMounted(() => {
    applyTheme(appSettings.value);
    loadGraph();
});

function handleUpdateSettings(newSettings: AppSettings) {
    appSettings.value = newSettings;
}

/**
 * Сервер отдаёт код причины, текст подбираем на текущем языке интерфейса.
 * Неизвестный код (сетевая ошибка, 419, 500) отдаём как есть, чтобы не врать
 * пользователю про несуществующий цикл.
 */
function describeServerError(data: {
    reason?: string;
    conflicts?: number;
    error?: string;
    message?: string;
}): string {
    const t = translations[appSettings.value.lang];

    switch (data.reason) {
        case 'self_link':
            return t.linkErrSelf;
        case 'single_parent':
            return data.conflicts === undefined
                ? t.linkErrSingleParent
                : t.structureErrSingleParent.replace(
                      '{n}',
                      String(data.conflicts),
                  );
        case 'cycle':
            return data.conflicts === undefined
                ? t.linkErrCycle
                : t.structureErrCycle;
        case 'level_gap':
            return data.conflicts === undefined
                ? t.linkErrLevelGap
                : t.structureErrLevelGap.replace('{n}', String(data.conflicts));
        case 'duplicate_keys':
        case 'unknown_edge_key':
            return t.importBadFormat;
        case 'undo_expired':
            return t.undoExpired;
        default:
            return data.error ?? data.message ?? 'Request failed.';
    }
}

async function handleUpdateStructure(structure: SpaceStructure) {
    structureError.value = null;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/structure`,
            {
                method: 'PUT',
                body: JSON.stringify({ structure }),
            },
        );

        if (!res.ok) {
            structureError.value = describeServerError(await res.json());

            return;
        }

        spaceStructure.value = structure;
    } catch (err) {
        console.error('Failed to update space structure:', err);
    }
}

/** Отправляет накопленные файлы и ссылки уже существующему узлу. */
async function uploadPending(nodeId: number, pending: PendingAttachment[]) {
    for (const item of pending) {
        const form = new FormData();

        form.append('kind', item.kind);
        form.append('label', item.label);

        if (item.kind === 'file' && item.file) {
            form.append('file', item.file);
        } else if (item.url) {
            form.append('url', item.url);
        }

        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/${nodeId}/attachments`,
            { method: 'POST', body: form },
        );

        if (!res.ok) {
            alert(translations[appSettings.value.lang].uploadFailed);

            return;
        }
    }
}

/** Загружает логотип уже существующему узлу. */
async function uploadLogo(nodeId: number, file: File) {
    const form = new FormData();
    form.append('logo', file);

    await apiFetch(`/api/spaces/${props.currentSpace.id}/nodes/${nodeId}/logo`, {
        method: 'POST',
        body: form,
    });
}

async function handleRemoveAttachment(attachmentId: number) {
    const nodeId = selectedNode.value?.id;

    if (!nodeId) {
        return;
    }

    try {
        await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/${nodeId}/attachments/${attachmentId}`,
            { method: 'DELETE' },
        );
        await loadGraph();
        selectedNode.value =
            nodes.value.find((n) => n.id === nodeId) ?? selectedNode.value;
    } catch (err) {
        console.error('Failed to remove attachment:', err);
    }
}

// Actions
function handleSelectNode(node: NodeData | null) {
    selectedNode.value = node;
}

function handleHoverNode(node: NodeData | null) {
    hoveredNode.value = node;
}

function handleFocusNode(nodeId: number) {
    if (sceneRef.value) {
        sceneRef.value.focusNode(nodeId);
    }

    const n = nodes.value.find((x) => x.id === nodeId);

    if (n) {
        selectedNode.value = n;
    }
}

function handleFocusPos(pos: { x: number; y: number }) {
    sceneRef.value?.focusPos(pos);
}

async function handleMoveNode(payload: {
    id: number;
    pos_x: number;
    pos_y: number;
    pos_z: number;
}) {
    try {
        await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/${payload.id}/move`,
            {
                method: 'PUT',
                body: JSON.stringify(payload),
            },
        );
        const idx = nodes.value.findIndex((n) => n.id === payload.id);

        if (idx !== -1) {
            nodes.value[idx].pos_x = payload.pos_x;
            nodes.value[idx].pos_y = payload.pos_y;
            nodes.value[idx].pos_z = payload.pos_z;
        }
    } catch (err) {
        console.error('Failed to move node:', err);
    }
}

function openAddRootModal() {
    addModalParentNode.value = null;
    showAddModal.value = true;
}

function openAddChildModal() {
    if (!selectedNode.value) {
        return;
    }

    addModalParentNode.value = selectedNode.value;
    showAddModal.value = true;
}

const isAnyModalOpen = computed(
    () =>
        showSpaceModal.value ||
        showSettingsModal.value ||
        showAddModal.value ||
        showEditModal.value ||
        showLinkModal.value ||
        showCopyModal.value ||
        showDeleteModal.value,
);

/** Пока фокус в поле ввода, буквенные хоткеи должны просто печататься как текст. */
function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable
    );
}

/**
 * Глобальные хоткеи — их же список показан пользователю в настройках
 * (см. SettingsPanel), так что при добавлении нового держим оба места в синхроне.
 */
function handleGlobalKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        if (isAnyModalOpen.value) {
            showSpaceModal.value = false;
            showSettingsModal.value = false;
            showAddModal.value = false;
            showEditModal.value = false;
            showLinkModal.value = false;
            showCopyModal.value = false;
            showDeleteModal.value = false;
        } else if (selectedNode.value) {
            selectedNode.value = null;
        }

        return;
    }

    if (isTypingTarget(event.target)) {
        return;
    }

    // Отмена удаления — до общей проверки isAnyModalOpen, чтобы работать
    // даже если тост с отменой висит поверх чего-то ещё.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
        if (undoToken.value) {
            event.preventDefault();
            handleUndo();
        }

        return;
    }

    if (isAnyModalOpen.value) {
        return;
    }

    switch (event.key) {
        case '/':
            event.preventDefault();
            hudRef.value?.focusSearch();
            break;
        case 'n':
        case 'N':
            if (selectedNode.value) {
                openAddChildModal();
            } else {
                openAddRootModal();
            }

            break;
        case 'e':
        case 'E':
            if (selectedNode.value) {
                showEditModal.value = true;
            }

            break;
        case 'l':
        case 'L':
            if (selectedNode.value) {
                showLinkModal.value = true;
            }

            break;
        case 'Delete':
        case 'Backspace':
            if (selectedNode.value) {
                showDeleteModal.value = true;
            }

            break;
        case 'a':
        case 'A':
            handleAutoLayout();
            break;
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
});

async function handleAddNode(payload: {
    title: string;
    description: string;
    color: string;
    tags: string;
    map_lat: number | null;
    map_lon: number | null;
    map_title: string | null;
    pos_x: number | null;
    pos_y: number | null;
    shape: NodeShape;
    logoFile: File | null;
    pending: PendingAttachment[];
}) {
    showAddModal.value = false;

    const { pending, logoFile, ...fields } = payload;
    const parent = addModalParentNode.value;

    try {
        const url = parent
            ? `/api/spaces/${props.currentSpace.id}/nodes/${parent.id}/child`
            : `/api/spaces/${props.currentSpace.id}/nodes/root`;

        const res = await apiFetch(url, {
            method: 'POST',
            body: JSON.stringify(fields),
        });
        const data = await res.json();
        const created = parent ? data.node : data;

        if (!created) {
            return;
        }

        // Вложения и логотип можно отправить только после того, как узел получил id.
        if (pending.length) {
            await uploadPending(created.id, pending);
        }

        if (logoFile) {
            await uploadLogo(created.id, logoFile);
        }

        await loadGraph();
        selectedNode.value =
            nodes.value.find((n) => n.id === created.id) ?? created;
    } catch (err) {
        console.error('Failed to add node:', err);
    }
}

/** Только в Admin-пространстве: «Добавить корень» создаёт учётку, а не обычный узел. */
async function handleAddUser(payload: {
    title: string;
    username: string;
    email: string;
    password: string;
    color: string;
    shape: NodeShape;
    tags: string;
    logoFile: File | null;
}) {
    showAddModal.value = false;

    const { logoFile, title, ...fields } = payload;

    try {
        const res = await apiFetch('/api/admin/users', {
            method: 'POST',
            // Бэкенд ждёт "name" — отображаемое имя узла-пользователя,
            // отдельно от "username" (логина).
            body: JSON.stringify({ ...fields, name: title }),
        });

        if (!res.ok) {
            alert(describeServerError(await res.json()));

            return;
        }

        const data = await res.json();

        if (logoFile) {
            await uploadLogo(data.node.id, logoFile);
        }

        await loadGraph();
        selectedNode.value =
            nodes.value.find((n) => n.id === data.node.id) ?? data.node;
    } catch (err) {
        console.error('Failed to create user:', err);
    }
}

async function handleEditNode(payload: {
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
    shape: NodeShape;
    logoFile: File | null;
    pending: PendingAttachment[];
}) {
    showEditModal.value = false;

    const { pending, logoFile, ...fields } = payload;

    try {
        await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/${payload.id}`,
            { method: 'PUT', body: JSON.stringify(fields) },
        );

        if (pending.length) {
            await uploadPending(payload.id, pending);
        }

        if (logoFile) {
            await uploadLogo(payload.id, logoFile);
        }

        await loadGraph();
        selectedNode.value =
            nodes.value.find((n) => n.id === payload.id) ?? null;
    } catch (err) {
        console.error('Failed to update node:', err);
    }
}

async function handleLinkNodes(payload: {
    parent_id: number;
    child_id: number;
}) {
    showLinkModal.value = false;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/links`,
            {
                method: 'POST',
                body: JSON.stringify(payload),
            },
        );

        if (!res.ok) {
            alert(describeServerError(await res.json()));

            return;
        }

        const edge = await res.json();
        edges.value.push(edge);
        await loadGraph();
    } catch (err) {
        console.error('Failed to link nodes:', err);
    }
}

async function handleCopyNode(payload: { parent_id: number | null }) {
    showCopyModal.value = false;

    if (!selectedNode.value) {
        return;
    }

    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/${selectedNode.value.id}/copy`,
            { method: 'POST', body: JSON.stringify(payload) },
        );

        if (!res.ok) {
            alert(describeServerError(await res.json()));

            return;
        }

        const copy = await res.json();
        await loadGraph();
        selectedNode.value = nodes.value.find((n) => n.id === copy.id) ?? null;
    } catch (err) {
        console.error('Failed to copy node:', err);
    }
}

async function handleConfirmDelete(deletionIds: number[]) {
    showDeleteModal.value = false;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/delete-many`,
            {
                method: 'POST',
                body: JSON.stringify({ ids: deletionIds }),
            },
        );
        const data = await res.json();

        undoToken.value = data.undo_token ?? null;
        selectedNode.value = null;
        await loadGraph();
    } catch (err) {
        console.error('Failed to delete nodes:', err);
    }
}

async function handleAutoLayout() {
    if (nodes.value.length <= 1) {
        return;
    }

    const positions = computeLayout(
        appSettings.value.layoutMode,
        nodes.value,
        edges.value,
        appSettings.value.layoutDirection,
    );

    const updatePayload: {
        id: number;
        pos_x: number;
        pos_y: number;
        pos_z: number;
    }[] = [];
    nodes.value.forEach((n) => {
        const pos = positions.get(n.id);

        if (!pos) {
            return;
        }

        n.pos_x = pos.x;
        n.pos_y = pos.y;
        n.pos_z = pos.z;
        updatePayload.push({
            id: n.id,
            pos_x: pos.x,
            pos_y: pos.y,
            pos_z: pos.z,
        });
    });

    try {
        await apiFetch(`/api/spaces/${props.currentSpace.id}/nodes/bulk-move`, {
            method: 'PUT',
            body: JSON.stringify({ positions: updatePayload }),
        });
    } catch (err) {
        console.error('Failed to bulk save layout:', err);
    }

    sceneRef.value?.fitToContent();
}

async function handleUndo() {
    if (!undoToken.value) {
        return;
    }

    try {
        const res = await apiFetch(
            `/api/spaces/${props.currentSpace.id}/nodes/restore`,
            {
                method: 'POST',
                body: JSON.stringify({ undo_token: undoToken.value }),
            },
        );

        // Токен одноразовый: сервер уже забрал снимок, повторять нечего.
        undoToken.value = null;

        if (!res.ok) {
            alert(describeServerError(await res.json()));

            return;
        }

        await loadGraph();
    } catch (err) {
        console.error('Failed to restore nodes:', err);
    }
}

// Space Chooser Actions
function handleSelectSpace(space: SpaceItem) {
    showSpaceModal.value = false;
    router.get('/', { space: space.slug });
}

async function handleCreateSpace(payload: {
    name: string;
    description: string;
}) {
    try {
        const res = await apiFetch('/api/spaces', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const space = await res.json();
        showSpaceModal.value = false;
        router.get('/', { space: space.slug });
    } catch (err) {
        console.error('Failed to create space:', err);
    }
}

async function handleImportSpace(payload: unknown) {
    const t = translations[appSettings.value.lang];

    if (payload === null || typeof payload !== 'object') {
        alert(t.importBadFormat);

        return;
    }

    try {
        const res = await apiFetch('/api/spaces/import', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        if (!res.ok) {
            const data = await res.json();

            // Ошибки валидации приходят без reason — значит файл просто не тот.
            alert(data.reason ? describeServerError(data) : t.importBadFormat);

            return;
        }

        const space = await res.json();

        router.get('/', { space: space.slug });
    } catch (err) {
        console.error('Failed to import space:', err);
    }
}

async function handleDeleteSpace(spaceId: number) {
    try {
        const res = await apiFetch(`/api/spaces/${spaceId}`, {
            method: 'DELETE',
        });

        if (!res.ok) {
            const err = await res.json();
            alert(err.error || 'Failed to delete space');

            return;
        }

        router.get('/');
    } catch (err) {
        console.error('Failed to delete space:', err);
    }
}
</script>

<template>
    <Head title="Infinite Space 3D" />

    <div
        class="relative h-screen w-screen overflow-hidden bg-slate-950 font-sans"
    >
        <!-- Top HUD Overlay -->
        <HudOverlay
            ref="hudRef"
            :space-name="currentSpace.name"
            :nodes="nodes"
            :edges-count="edges.length"
            :can-undo="!!undoToken"
            :settings="appSettings"
            @open-space-modal="showSpaceModal = true"
            @open-settings-modal="showSettingsModal = true"
            @open-add-root-modal="openAddRootModal"
            @focus-node="handleFocusNode"
            @undo="handleUndo"
            @auto-layout="handleAutoLayout"
            @update-filter="(f) => (filterState = f)"
        />

        <!-- Холст сцены -->
        <SpaceScene
            ref="sceneRef"
            :nodes="nodes"
            :edges="edges"
            :selected-node-id="selectedNode?.id ?? null"
            :hovered-node-id="hoveredNode?.id ?? null"
            :filtered-node-ids="filteredNodeIds"
            :settings="appSettings"
            @select-node="handleSelectNode"
            @hover-node="handleHoverNode"
            @move-node="handleMoveNode"
            @update-camera="(c) => (cameraState = c)"
        />

        <!-- Interactive 2D Minimap -->
        <Minimap
            v-if="appSettings.showMinimap"
            :nodes="nodes"
            :edges="edges"
            :selected-node-id="selectedNode?.id ?? null"
            :camera-pos="cameraState"
            :settings="appSettings"
            @focus-pos="handleFocusPos"
        />

        <!-- Selected Node Info Popup / Dialog -->
        <NodeInfoDialog
            :space-id="currentSpace.id"
            :node="selectedNode"
            :children-count="
                edges.filter((e) => e.parent_id === selectedNode?.id).length
            "
            :parents-count="
                edges.filter((e) => e.child_id === selectedNode?.id).length
            "
            @close="selectedNode = null"
            @open-add-child="openAddChildModal"
            @open-edit="showEditModal = true"
            @open-link="showLinkModal = true"
            @open-copy="showCopyModal = true"
            @delete="showDeleteModal = true"
        />

        <!-- Modals -->
        <SpaceChooserModal
            v-if="showSpaceModal"
            :spaces="spaces"
            :current-space-id="currentSpace.id"
            @close="showSpaceModal = false"
            @select-space="handleSelectSpace"
            @create-space="handleCreateSpace"
            @delete-space="handleDeleteSpace"
            @import-space="handleImportSpace"
        />

        <AddNodeModal
            v-if="showAddModal"
            :parent-node="addModalParentNode"
            :is-admin-space="currentSpace.is_admin"
            @close="showAddModal = false"
            @submit="handleAddNode"
            @submit-user="handleAddUser"
        />

        <EditNodeModal
            v-if="showEditModal"
            :node="selectedNode"
            @close="showEditModal = false"
            @submit="handleEditNode"
            @remove-attachment="handleRemoveAttachment"
        />

        <LinkNodeDialog
            v-if="showLinkModal && selectedNode"
            :source-node="selectedNode"
            :all-nodes="nodes"
            @close="showLinkModal = false"
            @submit="handleLinkNodes"
        />

        <CopyNodeModal
            v-if="showCopyModal && selectedNode"
            :source-node="selectedNode"
            :all-nodes="nodes"
            @close="showCopyModal = false"
            @submit="handleCopyNode"
        />

        <DeleteConfirmModal
            v-if="showDeleteModal && selectedNode"
            :node="selectedNode"
            :space-id="currentSpace.id"
            @close="showDeleteModal = false"
            @confirm="handleConfirmDelete"
        />

        <SettingsPanel
            v-if="showSettingsModal"
            :settings="appSettings"
            :space-name="currentSpace.name"
            :structure="spaceStructure"
            :structure-error="structureError"
            @close="
                showSettingsModal = false;
                structureError = null;
            "
            @update-settings="handleUpdateSettings"
            @update-structure="handleUpdateStructure"
        />
    </div>
</template>
