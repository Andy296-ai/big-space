<script setup lang="ts">
import {
    CheckCircle2,
    Download,
    Layers,
    Plus,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { useT } from '../lib/i18n';
import type { SpaceStructure } from '../types/settings';

export interface SpaceItem {
    id: number;
    name: string;
    slug: string;
    description: string;
    structure: SpaceStructure;
    nodes_count?: number;
    edges_count?: number;
}

defineProps<{
    spaces: SpaceItem[];
    currentSpaceId: number;
}>();

const t = useT();

const fileInput = ref<HTMLInputElement | null>(null);

function exportSpace(spaceId: number) {
    // Обычная ссылка: GET за сессией, браузер сам сохранит файл.
    window.location.href = `/api/spaces/${spaceId}/export`;
}

function pickFile() {
    fileInput.value?.click();
}

async function onFileChosen(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = ''; // чтобы тот же файл можно было выбрать повторно

    if (!file) {
        return;
    }

    try {
        emit('import-space', JSON.parse(await file.text()));
    } catch {
        emit('import-space', null);
    }
}

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select-space', space: SpaceItem): void;
    (e: 'create-space', payload: { name: string; description: string }): void;
    (e: 'delete-space', spaceId: number): void;
    (e: 'import-space', payload: unknown): void;
}>();

const isCreating = ref(false);
const newName = ref('');
const newDescription = ref('');

function handleCreate() {
    if (!newName.value.trim()) {
        return;
    }

    emit('create-space', {
        name: newName.value.trim(),
        description: newDescription.value.trim(),
    });
    newName.value = '';
    newDescription.value = '';
    isCreating.value = false;
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
    >
        <div
            class="animate-in fade-in zoom-in-95 w-full max-w-lg space-y-5 rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <Layers class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">{{ t.spacesTitle }}</h3>
                        <p class="text-xs text-slate-400">
                            {{ t.spacesSubtitle }}
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

            <!-- Spaces List -->
            <div
                v-if="!isCreating"
                class="max-h-64 space-y-2 overflow-y-auto pr-1"
            >
                <div
                    v-for="s in spaces"
                    :key="s.id"
                    :class="[
                        'flex items-center justify-between rounded-2xl border p-3.5 transition-all',
                        s.id === currentSpaceId
                            ? 'border-blue-500/80 bg-blue-600/15'
                            : 'border-slate-700/60 bg-slate-800/60 hover:bg-slate-800',
                    ]"
                >
                    <div
                        class="flex-1 cursor-pointer"
                        @click="emit('select-space', s)"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-slate-100">{{
                                s.name
                            }}</span>
                            <CheckCircle2
                                v-if="s.id === currentSpaceId"
                                class="h-4 w-4 shrink-0 text-blue-400"
                            />
                        </div>
                        <p
                            v-if="s.description"
                            class="mt-0.5 line-clamp-1 text-xs text-slate-400"
                        >
                            {{ s.description }}
                        </p>
                        <div class="mt-1 text-[10px] text-slate-500">
                            {{ s.nodes_count ?? 0 }} {{ t.nodes }} •
                            {{ s.edges_count ?? 0 }} {{ t.edges }}
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            @click.stop="exportSpace(s.id)"
                            class="rounded-xl p-2 text-slate-500 transition-colors hover:bg-slate-700/40 hover:text-blue-300"
                            :title="t.exportSpace"
                        >
                            <Download class="h-4 w-4" />
                        </button>

                        <button
                            v-if="spaces.length > 1"
                            @click.stop="emit('delete-space', s.id)"
                            class="rounded-xl p-2 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
                            :title="t.deleteSpaceTitle"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Create Form -->
            <div v-else class="space-y-3 text-xs">
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.spaceNameLabel
                    }}</label>
                    <input
                        v-model="newName"
                        type="text"
                        :placeholder="t.spaceNamePlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.spaceDescLabel
                    }}</label>
                    <textarea
                        v-model="newDescription"
                        rows="3"
                        :placeholder="t.spaceDescPlaceholder"
                        class="w-full resize-none rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>
            </div>

            <div
                class="flex items-center justify-between border-t border-slate-800 pt-3"
            >
                <button
                    v-if="!isCreating"
                    @click="isCreating = true"
                    class="flex items-center gap-1.5 rounded-xl bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500"
                >
                    <Plus class="h-4 w-4" />
                    <span>{{ t.newSpace }}</span>
                </button>

                <button
                    v-if="!isCreating"
                    @click="pickFile"
                    class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/60 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-slate-800"
                >
                    <Upload class="h-4 w-4" />
                    <span>{{ t.importSpace }}</span>
                </button>

                <input
                    ref="fileInput"
                    type="file"
                    accept="application/json,.json"
                    class="hidden"
                    @change="onFileChosen"
                />

                <div v-if="isCreating" class="ml-auto flex items-center gap-2">
                    <button
                        @click="isCreating = false"
                        class="rounded-xl px-3.5 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        @click="handleCreate"
                        class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500"
                    >
                        {{ t.createSpace }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
