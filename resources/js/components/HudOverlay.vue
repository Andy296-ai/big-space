<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Activity,
    Search,
    Filter,
    MessageCircle,
    MoreHorizontal,
    Plus,
    RotateCcw,
    Layers,
    LogOut,
    ScrollText,
    Telescope,
    Users,
    Wand2,
    Settings,
    X,
} from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { translations } from '../types/settings';
import type { AppSettings } from '../types/settings';
import NodusMark from './NodusMark.vue';
import NotificationBell from './NotificationBell.vue';
import type { NodeData, PresenceUser } from './SpaceScene.vue';

const props = defineProps<{
    spaceName: string;
    nodes: NodeData[];
    edgesCount: number;
    canUndo: boolean;
    settings: AppSettings;
    isRoot: boolean;
    canEdit: boolean;
    canViewSpaceActivity: boolean;
    presenceUsers: PresenceUser[];
    unreadMessageCount: number;
}>();

const emit = defineEmits<{
    (e: 'open-space-modal'): void;
    (e: 'open-settings-modal'): void;
    (e: 'open-add-root-modal'): void;
    (e: 'open-activity-log'): void;
    (e: 'open-space-activity-log'): void;
    (e: 'open-global-search'): void;
    (e: 'open-messenger'): void;
    (e: 'open-team-manager'): void;
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
// На узких экранах (< md) строка поиска по умолчанию свёрнута в иконку —
// иначе она навсегда занимала бы отдельную строку в и без того тесном HUD.
// На md и шире игнорируется: там строка всегда открыта (см. класс в шаблоне).
const searchExpanded = ref(false);

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

/**
 * Меню "ещё" — узкие экраны (< md): второстепенные действия сворачиваются
 * сюда, чтобы верхний бар не переполнялся. Тот же приём, что и у
 * NotificationBell.vue: Teleport в body + позиция считается вручную через
 * getBoundingClientRect(), а не CSS-абсолютом внутри пилюли — у пилюль тут
 * свой backdrop-blur, который создаёт отдельный stacking context и иначе
 * обрезал бы выпадашку (см. комментарий в самом NotificationBell.vue).
 */
const moreMenuOpen = ref(false);
const moreMenuPos = ref({ top: 0, left: 0 });
const moreButtonEl = ref<HTMLDivElement | null>(null);
const moreDropdownEl = ref<HTMLDivElement | null>(null);

function toggleMoreMenu() {
    if (!moreMenuOpen.value && moreButtonEl.value) {
        const rect = moreButtonEl.value.getBoundingClientRect();
        moreMenuPos.value = { top: rect.bottom + 8, left: rect.right - 288 };
    }

    moreMenuOpen.value = !moreMenuOpen.value;
}

function closeMoreMenu() {
    moreMenuOpen.value = false;
}

function selectMoreMenuAction(action: () => void) {
    action();
    closeMoreMenu();
}

function handleMoreMenuClickOutside(event: MouseEvent) {
    const target = event.target as Node;

    if (
        moreMenuOpen.value &&
        !moreButtonEl.value?.contains(target) &&
        !moreDropdownEl.value?.contains(target)
    ) {
        closeMoreMenu();
    }
}

onMounted(() => document.addEventListener('click', handleMoreMenuClickOutside));
onUnmounted(() =>
    document.removeEventListener('click', handleMoreMenuClickOutside),
);
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
            <div class="mx-1 h-6 w-px bg-slate-700 max-md:hidden" />
            <button
                @click="emit('open-global-search')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white max-md:hidden"
                :title="`${t.globalSearchAction} (Ctrl/⌘ K)`"
            >
                <Telescope class="h-4 w-4" />
            </button>
            <button
                v-if="isRoot"
                @click="emit('open-activity-log')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white max-md:hidden"
                :title="t.activityLogAction"
            >
                <ScrollText class="h-4 w-4" />
            </button>
            <button
                v-if="isRoot"
                @click="emit('open-team-manager')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white max-md:hidden"
                :title="t.teamManagerAction"
            >
                <Users class="h-4 w-4" />
            </button>
            <button
                v-if="canViewSpaceActivity"
                @click="emit('open-space-activity-log')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white max-md:hidden"
                :title="t.spaceActivityLogAction"
            >
                <Activity class="h-4 w-4" />
            </button>
            <div class="max-md:hidden">
                <NotificationBell />
            </div>
            <button
                @click="emit('open-messenger')"
                class="relative rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white"
                :title="t.messengerAction"
            >
                <MessageCircle class="h-4 w-4" />
                <span
                    v-if="unreadMessageCount > 0"
                    class="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white"
                >
                    {{ unreadMessageCount > 9 ? '9+' : unreadMessageCount }}
                </span>
            </button>
            <button
                @click="emit('open-settings-modal')"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white max-md:hidden"
                :title="t.settingsTitle"
            >
                <Settings class="h-4 w-4" />
            </button>
            <button
                @click="signOut"
                class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:border-rose-500/40 hover:bg-rose-600/20 hover:text-rose-300 max-md:hidden"
                :title="t.signOut"
            >
                <LogOut class="h-4 w-4" />
            </button>

            <!-- "Ещё": на узких экранах (< md) сюда сворачивается всё выше, что скрыто max-md:hidden -->
            <div ref="moreButtonEl" class="md:hidden">
                <button
                    @click="toggleMoreMenu"
                    class="rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white"
                    :title="t.moreActionsLabel"
                >
                    <MoreHorizontal class="h-4 w-4" />
                </button>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="moreMenuOpen"
                ref="moreDropdownEl"
                :style="{
                    top: `${moreMenuPos.top}px`,
                    left: `${moreMenuPos.left}px`,
                }"
                class="fixed z-50 w-72 max-w-[calc(100vw-2rem)] space-y-0.5 overflow-hidden rounded-2xl border border-slate-700/80 bg-slate-900/95 p-1.5 shadow-2xl backdrop-blur-md"
            >
                <button
                    @click="
                        selectMoreMenuAction(() => emit('open-global-search'))
                    "
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-800"
                >
                    <Telescope class="h-4 w-4 text-slate-400" />
                    <span>{{ t.globalSearchAction }}</span>
                </button>
                <button
                    v-if="isRoot"
                    @click="
                        selectMoreMenuAction(() => emit('open-activity-log'))
                    "
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-800"
                >
                    <ScrollText class="h-4 w-4 text-slate-400" />
                    <span>{{ t.activityLogAction }}</span>
                </button>
                <button
                    v-if="isRoot"
                    @click="
                        selectMoreMenuAction(() => emit('open-team-manager'))
                    "
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-800"
                >
                    <Users class="h-4 w-4 text-slate-400" />
                    <span>{{ t.teamManagerAction }}</span>
                </button>
                <button
                    v-if="canViewSpaceActivity"
                    @click="
                        selectMoreMenuAction(() =>
                            emit('open-space-activity-log'),
                        )
                    "
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-800"
                >
                    <Activity class="h-4 w-4 text-slate-400" />
                    <span>{{ t.spaceActivityLogAction }}</span>
                </button>
                <div
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-xs font-semibold text-slate-200"
                >
                    <NotificationBell />
                    <span>{{ t.notificationsAction }}</span>
                </div>
                <button
                    @click="
                        selectMoreMenuAction(() => emit('open-settings-modal'))
                    "
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-800"
                >
                    <Settings class="h-4 w-4 text-slate-400" />
                    <span>{{ t.settingsTitle }}</span>
                </button>
                <button
                    @click="selectMoreMenuAction(signOut)"
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-semibold text-rose-300 transition-colors hover:bg-rose-600/20"
                >
                    <LogOut class="h-4 w-4" />
                    <span>{{ t.signOut }}</span>
                </button>

                <template v-if="presenceUsers.length || settings.showStats">
                    <div class="my-1 h-px bg-slate-800" />
                    <div
                        v-if="presenceUsers.length"
                        class="flex items-center gap-2 px-3 py-2 text-[11px] text-slate-400"
                        :title="presenceUsers.map((u) => u.name).join(', ')"
                    >
                        <div class="flex -space-x-2">
                            <span
                                v-for="u in presenceUsers.slice(0, 4)"
                                :key="u.id"
                                class="flex h-5 w-5 items-center justify-center rounded-full border-2 border-slate-900 bg-emerald-600 text-[9px] font-bold text-white uppercase"
                            >
                                {{ u.name.slice(0, 2) }}
                            </span>
                        </div>
                        <span>{{
                            presenceUsers.map((u) => u.name).join(', ')
                        }}</span>
                    </div>
                    <div
                        v-if="settings.showStats"
                        class="flex items-center gap-3 px-3 py-2 text-[11px] text-slate-400"
                    >
                        <div class="flex items-center gap-1.5">
                            <Layers class="h-3.5 w-3.5 text-blue-400" />
                            <span class="font-bold text-slate-200">{{
                                nodes.length
                            }}</span>
                            {{ t.nodes }}
                        </div>
                        <div>
                            <span class="font-bold text-slate-200">{{
                                edgesCount
                            }}</span>
                            {{ t.edges }}
                        </div>
                    </div>
                </template>
            </div>
        </Teleport>

        <!-- Center: Search Bar & Auto-Focus -->
        <!-- На мобильном по умолчанию свёрнуто в иконку — сама строка вечно
             занимала бы отдельную строку HUD на узком экране. -->
        <button
            v-if="!searchExpanded"
            @click="
                searchExpanded = true;
                focusSearch();
            "
            class="pointer-events-auto rounded-2xl border border-slate-700/60 bg-slate-900/80 p-2.5 text-slate-300 shadow-xl backdrop-blur-md transition-colors hover:bg-slate-800 hover:text-white md:hidden"
            :title="t.globalSearchAction"
        >
            <Search class="h-4 w-4" />
        </button>
        <div
            :class="[
                'pointer-events-auto relative w-full max-w-md',
                searchExpanded ? 'block' : 'hidden md:block',
            ]"
        >
            <div
                class="flex items-center gap-2 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-3 py-2 text-slate-200 shadow-xl backdrop-blur-md"
            >
                <Search class="h-4 w-4 shrink-0 text-slate-400" />
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
                        'shrink-0 rounded-xl border p-1.5 transition-colors',
                        isFilterOpen
                            ? 'border-blue-500 bg-blue-600/30 text-blue-300'
                            : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:text-slate-200',
                    ]"
                    :title="t.filterTitle"
                >
                    <Filter class="h-4 w-4" />
                </button>
                <button
                    @click="
                        searchExpanded = false;
                        showSearchResults = false;
                        isFilterOpen = false;
                    "
                    class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:text-slate-200 md:hidden"
                    :aria-label="t.close"
                >
                    <X class="h-4 w-4" />
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
                class="absolute top-full right-0 z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] space-y-3 rounded-2xl border border-slate-700/80 bg-slate-900/95 p-4 text-slate-200 shadow-2xl backdrop-blur-md"
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
            <!-- Кто ещё сейчас смотрит это пространство -->
            <div
                v-if="presenceUsers.length"
                class="hidden items-center -space-x-2 rounded-2xl border border-slate-700/60 bg-slate-900/80 py-1.5 pr-3 pl-1.5 shadow-xl backdrop-blur-md md:flex"
                :title="presenceUsers.map((u) => u.name).join(', ')"
            >
                <span
                    v-for="u in presenceUsers.slice(0, 4)"
                    :key="u.id"
                    class="flex h-6 w-6 items-center justify-center rounded-full border-2 border-slate-900 bg-emerald-600 text-[10px] font-bold text-white uppercase"
                >
                    {{ u.name.slice(0, 2) }}
                </span>
                <span
                    v-if="presenceUsers.length > 4"
                    class="ms-1 text-[10px] font-semibold text-slate-400"
                >
                    +{{ presenceUsers.length - 4 }}
                </span>
            </div>

            <!-- Stats Pill -->
            <div
                v-if="settings.showStats"
                class="hidden items-center gap-3 rounded-2xl border border-slate-700/60 bg-slate-900/80 px-4 py-2.5 text-xs text-slate-300 shadow-xl backdrop-blur-md md:flex"
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
                v-if="canEdit"
                @click="emit('auto-layout')"
                class="flex items-center gap-2 rounded-2xl border border-indigo-500/40 bg-indigo-600/30 px-3.5 py-2.5 text-xs font-semibold text-indigo-200 shadow-xl backdrop-blur-md transition-all hover:bg-indigo-600/50 active:scale-95"
                :title="t.autoOrganize"
            >
                <Wand2 class="h-4 w-4 text-indigo-400" />
                <span class="hidden md:inline">{{ t.autoOrganize }}</span>
            </button>

            <!-- Add Root Button -->
            <button
                v-if="canEdit"
                @click="emit('open-add-root-modal')"
                class="flex items-center gap-2 rounded-2xl border border-blue-400/30 bg-blue-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xl transition-all hover:bg-blue-500 active:scale-95"
            >
                <Plus class="h-4 w-4" />
                <span>{{ t.addRootNode }}</span>
            </button>
        </div>
    </header>
</template>
