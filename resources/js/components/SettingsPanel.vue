<script setup lang="ts">
import {
    Box,
    Check,
    GitBranch,
    ListTree,
    Globe,
    Keyboard,
    Layout,
    Network,
    Palette,
    RotateCcw,
    Settings,
    Sparkles,
    TriangleAlert,
    Waypoints,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    DEFAULT_SETTINGS,
    NODE_SCALE_MAX,
    NODE_SCALE_MIN,
    THEME_TOKENS,
    translations,
} from '../types/settings';
import type {
    AppSettings,
    ColorMode,
    LayoutDirection,
    Language,
    LayoutMode,
    SpaceStructure,
    ThemeMode,
} from '../types/settings';

const props = defineProps<{
    settings: AppSettings;
    spaceName: string;
    structure: SpaceStructure;
    structureError: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'update-settings', newSettings: AppSettings): void;
    (e: 'update-structure', structure: SpaceStructure): void;
}>();

type TranslationKey = keyof typeof translations.en;
type BooleanSettingKey = {
    [K in keyof AppSettings]: AppSettings[K] extends boolean ? K : never;
}[keyof AppSettings];

const localSettings = ref<AppSettings>({ ...props.settings });

watch(
    () => props.settings,
    (next) => {
        localSettings.value = { ...next };
    },
);

const t = computed(() => translations[localSettings.value.lang]);

type TabId = 'appearance' | 'structure' | 'space' | 'interface';

const tabs: { id: TabId; labelKey: TranslationKey; icon: typeof Palette }[] = [
    { id: 'appearance', labelKey: 'tabAppearance', icon: Palette },
    { id: 'structure', labelKey: 'tabStructure', icon: Network },
    { id: 'space', labelKey: 'tabSpace', icon: Box },
    { id: 'interface', labelKey: 'tabInterface', icon: Layout },
];

const activeTab = ref<TabId>('appearance');

const structureOptions: {
    id: SpaceStructure;
    labelKey: TranslationKey;
    descKey: TranslationKey;
    icon: typeof Palette;
}[] = [
    {
        id: 'tree',
        labelKey: 'structureTree',
        descKey: 'structureTreeDesc',
        icon: GitBranch,
    },
    {
        id: 'leveled',
        labelKey: 'structureLeveled',
        descKey: 'structureLeveledDesc',
        icon: ListTree,
    },
    {
        id: 'dag',
        labelKey: 'structureDag',
        descKey: 'structureDagDesc',
        icon: Network,
    },
    {
        id: 'network',
        labelKey: 'structureNetwork',
        descKey: 'structureNetworkDesc',
        icon: Waypoints,
    },
];

const languageOptions: { id: Language; flag: string; label: string }[] = [
    { id: 'en', flag: '🇬🇧', label: 'English' },
    { id: 'ru', flag: '🇷🇺', label: 'Русский' },
    { id: 'tg', flag: '🇹🇯', label: 'Тоҷикӣ' },
    { id: 'fa', flag: '🇮🇷', label: 'فارسی' },
];

const themeOptions: { id: ThemeMode; labelKey: TranslationKey }[] = [
    { id: 'cosmic', labelKey: 'themeCosmic' },
    { id: 'midnight', labelKey: 'themeMidnight' },
    { id: 'cyberpunk', labelKey: 'themeCyberpunk' },
    { id: 'light', labelKey: 'themeLight' },
];

const layoutOptions: {
    id: LayoutMode;
    labelKey: TranslationKey;
    descKey: TranslationKey;
}[] = [
    {
        id: 'hierarchy',
        labelKey: 'layoutHierarchy',
        descKey: 'layoutHierarchyDesc',
    },
    { id: 'spiral', labelKey: 'layoutSpiral', descKey: 'layoutSpiralDesc' },
    { id: 'rings', labelKey: 'layoutRings', descKey: 'layoutRingsDesc' },
    { id: 'grid', labelKey: 'layoutGrid', descKey: 'layoutGridDesc' },
    { id: 'radial', labelKey: 'layoutRadial', descKey: 'layoutRadialDesc' },
    { id: 'layered', labelKey: 'layoutLayered', descKey: 'layoutLayeredDesc' },
    { id: 'cluster', labelKey: 'layoutCluster', descKey: 'layoutClusterDesc' },
    { id: 'force', labelKey: 'layoutForce', descKey: 'layoutForceDesc' },
];

const directionOptions: { id: LayoutDirection; labelKey: TranslationKey }[] = [
    { id: 'down', labelKey: 'dirDown' },
    { id: 'up', labelKey: 'dirUp' },
    { id: 'right', labelKey: 'dirRight' },
    { id: 'left', labelKey: 'dirLeft' },
];

const colorModeOptions: { id: ColorMode; labelKey: TranslationKey }[] = [
    { id: 'tree', labelKey: 'colorByTree' },
    { id: 'depth', labelKey: 'colorByDepth' },
];

const sceneToggles: { key: BooleanSettingKey; labelKey: TranslationKey }[] = [
    { key: 'curvedEdges', labelKey: 'curvedEdgesLabel' },
    { key: 'showGrid', labelKey: 'showGridLabel' },
    { key: 'showAxes', labelKey: 'showAxesLabel' },
    { key: 'showNodeLabels', labelKey: 'showNodeLabelsLabel' },
];

const interfaceToggles: { key: BooleanSettingKey; labelKey: TranslationKey }[] =
    [
        { key: 'compactHud', labelKey: 'compactHudLabel' },
        { key: 'showMinimap', labelKey: 'showMinimapLabel' },
        { key: 'showStats', labelKey: 'showStatsLabel' },
        { key: 'reduceMotion', labelKey: 'reduceMotionLabel' },
    ];

/** Список — просто шпаргалка; сами хоткеи навешаны в Welcome.vue, держим оба места в синхроне. */
const shortcuts: { keys: string; labelKey: TranslationKey }[] = [
    { keys: '/', labelKey: 'shortcutSearch' },
    { keys: 'Ctrl/⌘ K', labelKey: 'shortcutGlobalSearch' },
    { keys: 'N', labelKey: 'shortcutAddNode' },
    { keys: 'E', labelKey: 'shortcutEdit' },
    { keys: 'L', labelKey: 'shortcutLink' },
    { keys: 'Delete', labelKey: 'shortcutDelete' },
    { keys: 'Ctrl/⌘ Z', labelKey: 'shortcutUndo' },
    { keys: 'A', labelKey: 'shortcutAutoLayout' },
    { keys: 'Esc', labelKey: 'shortcutClose' },
];

function patch(partial: Partial<AppSettings>) {
    localSettings.value = { ...localSettings.value, ...partial };
    emit('update-settings', { ...localSettings.value });
}

function toggleOption(key: BooleanSettingKey) {
    patch({ [key]: !localSettings.value[key] } as Partial<AppSettings>);
}

function onNodeScaleInput(event: Event) {
    patch({ nodeScale: Number((event.target as HTMLInputElement).value) });
}

function resetToDefaults() {
    patch({ ...DEFAULT_SETTINGS });
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            role="dialog"
            aria-modal="true"
            class="animate-in fade-in zoom-in-95 flex max-h-[90vh] w-full max-w-xl flex-col rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl duration-200"
        >
            <!-- Шапка -->
            <div class="flex items-center justify-between gap-3 p-6 pb-4">
                <div class="flex min-w-0 items-center gap-2.5">
                    <div
                        class="shrink-0 rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <Settings class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="truncate text-base font-bold">
                            {{ t.settingsTitle }}
                        </h3>
                        <p class="truncate text-xs text-slate-400">
                            {{ t.settingsDesc }}
                        </p>
                    </div>
                </div>
                <button
                    @click="emit('close')"
                    :aria-label="t.close"
                    class="shrink-0 rounded-xl p-1.5 text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- Вкладки -->
            <div class="px-6">
                <div
                    class="flex items-center gap-1 rounded-2xl border border-slate-700/60 bg-slate-800/60 p-1"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        @click="activeTab = tab.id"
                        :class="[
                            'flex flex-1 items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold transition-all',
                            activeTab === tab.id
                                ? 'border border-blue-500/40 bg-blue-600/25 text-blue-300'
                                : 'border border-transparent text-slate-400 hover:text-slate-200',
                        ]"
                    >
                        <component
                            :is="tab.icon"
                            class="h-3.5 w-3.5 shrink-0"
                        />
                        <span class="truncate">{{ t[tab.labelKey] }}</span>
                    </button>
                </div>
            </div>

            <!-- Содержимое -->
            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5 text-xs">
                <!-- === Оформление === -->
                <template v-if="activeTab === 'appearance'">
                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Globe class="h-4 w-4 text-blue-400" />
                            <span>{{ t.languageLabel }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="lang in languageOptions"
                                :key="lang.id"
                                type="button"
                                @click="patch({ lang: lang.id })"
                                :class="[
                                    'flex items-center justify-between gap-2 rounded-xl border p-2.5 font-semibold transition-all',
                                    localSettings.lang === lang.id
                                        ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                                        : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                ]"
                            >
                                <span class="truncate"
                                    >{{ lang.flag }} {{ lang.label }}</span
                                >
                                <Check
                                    v-if="localSettings.lang === lang.id"
                                    class="h-4 w-4 shrink-0 text-blue-400"
                                />
                            </button>
                        </div>
                    </div>

                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Palette class="h-4 w-4 text-amber-400" />
                            <span>{{ t.themeLabel }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="th in themeOptions"
                                :key="th.id"
                                type="button"
                                @click="patch({ theme: th.id })"
                                :class="[
                                    'flex items-center gap-2.5 rounded-xl border p-2.5 text-start transition-all',
                                    localSettings.theme === th.id
                                        ? 'border-blue-500 bg-blue-600/20 text-slate-100'
                                        : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                ]"
                            >
                                <!-- Двухцветный кружок: фон сцены + акцент темы -->
                                <span
                                    class="flex h-5 w-5 shrink-0 overflow-hidden rounded-full border border-white/20"
                                    :style="{
                                        backgroundColor:
                                            THEME_TOKENS[th.id].canvasBg,
                                    }"
                                >
                                    <span
                                        class="h-full w-1/2"
                                        :style="{
                                            backgroundColor:
                                                THEME_TOKENS[th.id].accent,
                                        }"
                                    />
                                </span>
                                <span class="truncate font-medium">{{
                                    t[th.labelKey]
                                }}</span>
                                <Check
                                    v-if="localSettings.theme === th.id"
                                    class="ms-auto h-4 w-4 shrink-0 text-blue-400"
                                />
                            </button>
                        </div>
                    </div>
                </template>

                <!-- === Структура связей (свойство пространства, а не пользователя) === -->
                <template v-if="activeTab === 'structure'">
                    <div>
                        <div
                            class="mb-1 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Network class="h-4 w-4 text-emerald-400" />
                            <span>{{ t.structureLabel }}</span>
                        </div>
                        <p class="mb-3 text-[11px] text-slate-400">
                            <span class="font-semibold text-slate-300">{{
                                spaceName
                            }}</span>
                            — {{ t.structureHint }}
                        </p>

                        <div
                            v-if="structureError"
                            class="mb-3 flex items-start gap-2 rounded-2xl border border-rose-500/40 bg-rose-500/10 p-3 text-rose-400"
                        >
                            <TriangleAlert class="mt-px h-4 w-4 shrink-0" />
                            <span>{{ structureError }}</span>
                        </div>

                        <div class="space-y-2">
                            <button
                                v-for="s in structureOptions"
                                :key="s.id"
                                type="button"
                                @click="emit('update-structure', s.id)"
                                :class="[
                                    'flex w-full items-center gap-3 rounded-2xl border p-3 text-start transition-all',
                                    structure === s.id
                                        ? 'border-blue-500 bg-blue-600/20 text-slate-100'
                                        : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                ]"
                            >
                                <component
                                    :is="s.icon"
                                    class="h-5 w-5 shrink-0 text-blue-400"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block font-bold text-slate-100"
                                        >{{ t[s.labelKey] }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-[10px] text-slate-400"
                                        >{{ t[s.descKey] }}</span
                                    >
                                </span>
                                <Check
                                    v-if="structure === s.id"
                                    class="h-4 w-4 shrink-0 text-blue-400"
                                />
                            </button>
                        </div>
                    </div>
                </template>

                <!-- === 3D-пространство === -->
                <template v-if="activeTab === 'space'">
                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Box class="h-4 w-4 text-emerald-400" />
                            <span>{{ t.layoutLabel }}</span>
                        </div>
                        <div class="space-y-2">
                            <button
                                v-for="l in layoutOptions"
                                :key="l.id"
                                type="button"
                                @click="patch({ layoutMode: l.id })"
                                :class="[
                                    'flex w-full items-center justify-between gap-3 rounded-2xl border p-3 text-start transition-all',
                                    localSettings.layoutMode === l.id
                                        ? 'border-blue-500 bg-blue-600/20 text-slate-100'
                                        : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                ]"
                            >
                                <span class="min-w-0">
                                    <span
                                        class="block font-bold text-slate-100"
                                        >{{ t[l.labelKey] }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-[10px] text-slate-400"
                                        >{{ t[l.descKey] }}</span
                                    >
                                </span>
                                <Check
                                    v-if="localSettings.layoutMode === l.id"
                                    class="h-4 w-4 shrink-0 text-blue-400"
                                />
                            </button>
                        </div>
                    </div>

                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Sparkles class="h-4 w-4 text-purple-400" />
                            <span>{{ t.visualsLabel }}</span>
                        </div>

                        <!-- Направление уровней: имеет смысл для иерархии и слоёв -->
                        <div class="mb-2">
                            <div
                                class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                            >
                                <span>{{ t.directionLabel }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="d in directionOptions"
                                    :key="d.id"
                                    type="button"
                                    @click="patch({ layoutDirection: d.id })"
                                    :class="[
                                        'rounded-xl border p-2.5 text-start font-medium transition-all',
                                        localSettings.layoutDirection === d.id
                                            ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                                            : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                    ]"
                                >
                                    {{ t[d.labelKey] }}
                                </button>
                            </div>
                        </div>

                        <!-- Раскраска -->
                        <div class="mb-2">
                            <div
                                class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                            >
                                <span>{{ t.colorModeLabel }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="c in colorModeOptions"
                                    :key="c.id"
                                    type="button"
                                    @click="patch({ colorMode: c.id })"
                                    :class="[
                                        'rounded-xl border p-2.5 text-start font-medium transition-all',
                                        localSettings.colorMode === c.id
                                            ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                                            : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                    ]"
                                >
                                    {{ t[c.labelKey] }}
                                </button>
                            </div>
                        </div>

                        <!-- Размер узлов -->
                        <div
                            class="mb-2 rounded-2xl border border-slate-700 bg-slate-800/60 p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <span class="font-medium text-slate-200">{{
                                    t.nodeScaleLabel
                                }}</span>
                                <span class="font-mono font-bold text-blue-300"
                                    >{{
                                        localSettings.nodeScale.toFixed(1)
                                    }}×</span
                                >
                            </div>
                            <input
                                type="range"
                                :min="NODE_SCALE_MIN"
                                :max="NODE_SCALE_MAX"
                                step="0.1"
                                :value="localSettings.nodeScale"
                                :aria-label="t.nodeScaleLabel"
                                class="w-full cursor-pointer accent-blue-500"
                                @input="onNodeScaleInput"
                            />
                        </div>

                        <div class="space-y-2">
                            <button
                                v-for="item in sceneToggles"
                                :key="item.key"
                                type="button"
                                role="switch"
                                :aria-checked="localSettings[item.key]"
                                @click="toggleOption(item.key)"
                                class="flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-700 bg-slate-800/60 p-3 text-start transition-colors hover:bg-slate-800"
                            >
                                <span class="font-medium text-slate-200">{{
                                    t[item.labelKey]
                                }}</span>
                                <span
                                    :class="[
                                        'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                                        localSettings[item.key]
                                            ? 'bg-blue-500'
                                            : 'bg-slate-600',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all',
                                            localSettings[item.key]
                                                ? 'start-[1.125rem]'
                                                : 'start-0.5',
                                        ]"
                                    />
                                </span>
                            </button>
                        </div>
                    </div>
                </template>

                <!-- === Интерфейс === -->
                <template v-if="activeTab === 'interface'">
                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Layout class="h-4 w-4 text-cyan-400" />
                            <span>{{ t.interfaceLabel }}</span>
                        </div>
                        <div class="space-y-2">
                            <button
                                v-for="item in interfaceToggles"
                                :key="item.key"
                                type="button"
                                role="switch"
                                :aria-checked="localSettings[item.key]"
                                @click="toggleOption(item.key)"
                                class="flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-700 bg-slate-800/60 p-3 text-start transition-colors hover:bg-slate-800"
                            >
                                <span class="font-medium text-slate-200">{{
                                    t[item.labelKey]
                                }}</span>
                                <span
                                    :class="[
                                        'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                                        localSettings[item.key]
                                            ? 'bg-blue-500'
                                            : 'bg-slate-600',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all',
                                            localSettings[item.key]
                                                ? 'start-[1.125rem]'
                                                : 'start-0.5',
                                        ]"
                                    />
                                </span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <div
                            class="mb-2 flex items-center gap-1.5 font-semibold text-slate-300"
                        >
                            <Keyboard class="h-4 w-4 text-amber-400" />
                            <span>{{ t.shortcutsLabel }}</span>
                        </div>
                        <div
                            class="divide-y divide-slate-800 overflow-hidden rounded-2xl border border-slate-700 bg-slate-800/60"
                        >
                            <div
                                v-for="item in shortcuts"
                                :key="item.labelKey"
                                class="flex items-center justify-between gap-3 p-3"
                            >
                                <span class="text-slate-300">{{
                                    t[item.labelKey]
                                }}</span>
                                <kbd
                                    class="shrink-0 rounded-lg border border-slate-600 bg-slate-900 px-2 py-1 font-mono text-[10px] font-semibold text-slate-200"
                                    >{{ item.keys }}</kbd
                                >
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Подвал -->
            <div
                class="flex items-center justify-between gap-3 border-t border-slate-800 p-6 pt-4"
            >
                <button
                    type="button"
                    @click="resetToDefaults"
                    class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                >
                    <RotateCcw class="h-3.5 w-3.5" />
                    <span>{{ t.resetSettings }}</span>
                </button>
                <button
                    type="button"
                    @click="emit('close')"
                    class="rounded-xl bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-lg hover:bg-blue-500"
                >
                    {{ t.close }}
                </button>
            </div>
        </div>
    </div>
</template>
