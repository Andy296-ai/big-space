<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Search,
    Filter,
    Plus,
    RotateCcw,
    Layers,
    LogOut,
    ScrollText,
    Wand2,
    Settings,
} from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { translations } from '../types/settings';
import type { AppSettings } from '../types/settings';
import NodusMark from './NodusMark.vue';
import type { NodeData } from './SpaceScene.vue';

const props = defineProps<{
    spaceName: string;
    nodes: NodeData[];
    edgesCount: number;
    canUndo: boolean;
    settings: AppSettings;
    isRoot: boolean;
}>();

const emit = defineEmits<{
    (e: 'open-space-modal'): void;
    (e: 'open-settings-modal'): void;
    (e: 'open-add-root-modal'): void;
    (e: 'open-activity-log'): void;
    (e: 'focus-node', nodeId: number): void;
    (e: 'undo'): void;
    (e: 'auto-layout'): void;
    (
        e: 'update-filter',
        filter: {
            searchQuery: string;
            maxDepth: number | null;
            leavesOnly: boolean;
            tagQuery: string;
            createdFrom: string;
            createdTo: string;
        },
    ): void;
}>();

const t = computed(() => translations[props.settings.lang]);

const searchInputRef = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');
const isFilterOpen = ref(false);
const maxDepth = ref<number | null>(null);
const leavesOnly = ref(false);
const tagQuery = ref('');
const createdFrom = ref('');
const createdTo = ref('');
const showSearchResults = ref(false);

const searchResults = computed(() => {
    if (!searchQuery.value.trim()) {
        return [];
    }

    const q = searchQuery.value.toLowerCase();

    return props.nodes
        .filter(
            (n) =>
                n.title.toLowerCase().includes(q) ||
                n.description.toLowerCase().includes(q) ||
                n.tags.toLowerCase().includes(q) ||
                (n.attachments ?? []).some((a) =>
                    a.label.toLowerCase().includes(q),
                ),
        )
        .slice(0, 8);
});

function onSearchSelect(nodeId: number) {
    emit('focus-node', nodeId);
    showSearchResults.value = false;
    searchQuery.value = '';
}

function signOut() {
    router.post('/logout');
}

function applyFilters() {
    emit('update-filter', {
        searchQuery: searchQuery.value,
        maxDepth: maxDepth.value,
        leavesOnly: leavesOnly.value,
        tagQuery: tagQuery.value,
        createdFrom: createdFrom.value,
        createdTo: createdTo.value,
    });
}

/** Даёт родителю сфокусировать поиск по горячей клавише. */
function focusSearch() {
    searchInputRef.value?.focus();
    searchInputRef.value?.select();
}

defineExpose({ focusSearch });
</script>

<template>
    <header
        :class="[
            'pointer-events-none absolute top-4 right-4 left-4 z-20 flex flex-wrap items-center justify-between gap-3',
            settings.compactHud ? 'gap-2' : 'gap-3',
        ]"
    >
        <!-- Left: Space Chooser, Title & Settings -->
        <div
            :class="[
                'pointer-events-auto flex items-center rounded-2xl border border-slate-700/60 bg-slate-900/80 text-slate-100 shadow-xl backdrop-blur-md',
                settings.compactHud ? 'gap-2 px-3 py-1.5' : 'gap-3 px-4 py-2.5',
            ]"
        >
            <div
                class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
            >
                <NodusMark class="h-5 w-5" />
            </div>
            <div>
                <div
                    class="text-xs font-medium tracking-wider text-slate-400 uppercase"
                >
                    {{ t.currentSpace }}
                </div>
                <div class="flex items-center gap-2 text-base font-bold">
                    <span>{{ spaceName }}</span>
                    <button
                        @click="emit('open-space-modal')"
                        class="rounded-lg border border-slate-600/50 bg-slate-800 px-2 py-0.5 text-xs text-blue-300 transition-colors hover:bg-slate-700"
                    >
                        {{ t.switchSpace }}
                    </button>
                </div>
            </div>
            <div class="mx-1 h-6 w-px bg-slate-700" />
            <button
                v-if="isRoot"
                @click="emit('open-activity-log')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white"
                :title="t.activityLogAction"
            >
                <ScrollText class="h-4 w-4" />
            </button>
            <button
                @click="emit('open-settings-modal')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white"
                :title="t.settingsTitle"
            >
                <Settings class="h-4 w-4" />
            </button>
            <button
                @click="signOut"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:border-rose-500/40 hover:bg-rose-600/20 hover:text-rose-300"
                :title="t.signOut"
            >
                <LogOut class="h-4 w-4" />
            </button>
        </div>

        <!-- Center: Search Bar & Auto-Focus -->
        <div class="pointer-events-auto relative w-full max-w-md">
            <div
                class="flex items-center gap-2 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-3 py-2 text-slate-200 shadow-xl backdrop-blur-md"
            >
                <Search class="h-4 w-4 text-slate-400" />
                <input
                    ref="searchInputRef"
                    v-model="searchQuery"
                    type="text"
                    :placeholder="t.searchPlaceholder"
                    class="w-full bg-transparent text-sm text-slate-100 placeholder-slate-400 focus:outline-none"
                    @focus="showSearchResults = true"
                    @input="applyFilters"
                />
                <button
                    @click="isFilterOpen = !isFilterOpen"
                    :class="[
                        'rounded-xl border p-1.5 transition-colors',
                        isFilterOpen
                            ? 'border-blue-500 bg-blue-600/30 text-blue-300'
                            : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:text-slate-200',
                    ]"
                    :title="t.filterTitle"
                >
                    <Filter class="h-4 w-4" />
                </button>
            </div>

            <!-- Search Autocomplete Dropdown -->
            <div
                v-if="showSearchResults && searchResults.length > 0"
                class="absolute top-full right-0 left-0 z-30 mt-2 divide-y divide-slate-800 overflow-hidden rounded-2xl border border-slate-700/80 bg-slate-900/95 shadow-2xl backdrop-blur-md"
            >
                <div
                    v-for="n in searchResults"
                    :key="n.id"
                    @click="onSearchSelect(n.id)"
                    class="flex cursor-pointer items-center justify-between px-4 py-2.5 text-sm transition-colors hover:bg-blue-600/20"
                >
                    <div>
                        <div class="font-semibold text-slate-100">
                            {{ n.title || t.untitledNode }}
                        </div>
                        <div class="max-w-xs truncate text-xs text-slate-400">
                            {{ n.description || t.noDescription }}
                        </div>
                    </div>
                    <div
                        class="rounded border border-slate-700 bg-slate-800 px-2 py-0.5 text-xs text-slate-300"
                    >
                        {{ t.depthLabel }} {{ n.depth }}
                    </div>
                </div>
            </div>

            <!-- Filter Panel Popup -->
            <div
                v-if="isFilterOpen"
                class="absolute top-full right-0 z-30 mt-2 w-72 space-y-3 rounded-2xl border border-slate-700/80 bg-slate-900/95 p-4 text-slate-200 shadow-2xl backdrop-blur-md"
            >
                <div
                    class="text-xs font-bold tracking-wider text-slate-400 uppercase"
                >
                    {{ t.filterTitle }}
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-300">{{
                        t.maxDepthLabel
                    }}</label>
                    <input
                        v-model.number="maxDepth"
                        type="number"
                        min="0"
                        :placeholder="t.maxDepthPlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-300">{{
                        t.tagContainsLabel
                    }}</label>
                    <input
                        v-model="tagQuery"
                        type="text"
                        :placeholder="t.tagPlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        @input="applyFilters"
                    />
                </div>
                <label
                    class="flex cursor-pointer items-center gap-2 text-xs text-slate-300"
                >
                    <input
                        v-model="leavesOnly"
                        type="checkbox"
                        class="rounded border-slate-700 bg-slate-800 text-blue-500"
                        @change="applyFilters"
                    />
                    <span>{{ t.leavesOnlyLabel }}</span>
                </label>
                <div>
                    <label class="mb-1 block text-xs text-slate-300">{{
                        t.createdRangeLabel
                    }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input
                            v-model="createdFrom"
                            type="date"
                            :max="createdTo || undefined"
                            :aria-label="t.createdFromLabel"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            @change="applyFilters"
                        />
                        <input
                            v-model="createdTo"
                            type="date"
                            :min="createdFrom || undefined"
                            :aria-label="t.createdToLabel"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            @change="applyFilters"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Stats & Actions -->
        <div class="pointer-events-auto flex items-center gap-3">
            <!-- Stats Pill -->
            <div
                v-if="settings.showStats"
                class="hidden items-center gap-3 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-4 py-2.5 text-xs text-slate-300 shadow-xl backdrop-blur-md sm:flex"
            >
                <div class="flex items-center gap-1.5">
                    <Layers class="h-4 w-4 text-blue-400" />
                    <span class="font-bold text-slate-100">{{
                        nodes.length
                    }}</span>
                    {{ t.nodes }}
                </div>
                <div class="h-4 w-px bg-slate-700" />
                <div>
                    <span class="font-bold text-slate-100">{{
                        edgesCount
                    }}</span>
                    {{ t.edges }}
                </div>
            </div>

            <!-- Undo Button -->
            <button
                v-if="canUndo"
                @click="emit('undo')"
                class="flex items-center gap-2 rounded-2xl border border-amber-500/40 bg-amber-600/20 px-3.5 py-2.5 text-xs font-semibold text-amber-300 shadow-xl backdrop-blur-md transition-all hover:bg-amber-600/30 active:scale-95"
            >
                <RotateCcw class="h-4 w-4" />
                <span>{{ t.undoDelete }}</span>
            </button>

            <!-- Auto-Organize Layout Button -->
            <button
                @click="emit('auto-layout')"
                class="flex items-center gap-2 rounded-2xl border border-indigo-500/40 bg-indigo-600/30 px-3.5 py-2.5 text-xs font-semibold text-indigo-200 shadow-xl backdrop-blur-md transition-all hover:bg-indigo-600/50 active:scale-95"
                :title="t.autoOrganize"
            >
                <Wand2 class="h-4 w-4 text-indigo-400" />
                <span class="hidden md:inline">{{ t.autoOrganize }}</span>
            </button>

            <!-- Add Root Button -->
            <button
                @click="emit('open-add-root-modal')"
                class="flex items-center gap-2 rounded-2xl border border-blue-400/30 bg-blue-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xl transition-all hover:bg-blue-500 active:scale-95"
            >
                <Plus class="h-4 w-4" />
                <span>{{ t.addRootNode }}</span>
            </button>
        </div>
    </header>
</template>
