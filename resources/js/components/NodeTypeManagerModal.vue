<script setup lang="ts">
import { ArrowLeft, Layers, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { NodeTypeEntry, NodeTypeFieldSchema } from './SpaceScene.vue';

const props = defineProps<{
    spaceId: number;
    nodeTypes: NodeTypeEntry[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'changed'): void;
}>();

const t = useT();

type FieldKind = NodeTypeFieldSchema['type'];

const fieldKinds: { id: FieldKind; labelKey: keyof typeof t.value }[] = [
    { id: 'text', labelKey: 'fieldKindText' },
    { id: 'textarea', labelKey: 'fieldKindTextarea' },
    { id: 'number', labelKey: 'fieldKindNumber' },
    { id: 'date', labelKey: 'fieldKindDate' },
    { id: 'url', labelKey: 'fieldKindUrl' },
    { id: 'phone', labelKey: 'fieldKindPhone' },
    { id: 'checkbox', labelKey: 'fieldKindCheckbox' },
    { id: 'select', labelKey: 'fieldKindSelect' },
    { id: 'multiselect', labelKey: 'fieldKindMultiselect' },
    { id: 'relation', labelKey: 'fieldKindRelation' },
    { id: 'photo', labelKey: 'fieldKindPhoto' },
    { id: 'files', labelKey: 'fieldKindFiles' },
];

/** Черновик поля в редакторе — options хранится строкой, разбивается на запрос. */
interface FieldDraft {
    key: string;
    label: string;
    type: FieldKind;
    required: boolean;
    optionsText: string;
}

const editingType = ref<NodeTypeEntry | null>(null);
const isCreating = ref(false);
const draftKey = ref('');
const draftLabel = ref('');
const draftIcon = ref('');
const draftColor = ref('');
const draftFields = ref<FieldDraft[]>([]);
const saving = ref(false);
const errorMessage = ref<string | null>(null);

const presetColors = [
    '#3b82f6',
    '#10b981',
    '#8b5cf6',
    '#f59e0b',
    '#ec4899',
    '#06b6d4',
    '#84cc16',
    '#a855f7',
    '#f97316',
    '#14b8a6',
];

function startCreate() {
    isCreating.value = true;
    editingType.value = null;
    draftKey.value = '';
    draftLabel.value = '';
    draftIcon.value = '';
    draftColor.value = '';
    draftFields.value = [];
    errorMessage.value = null;
}

function startEdit(type: NodeTypeEntry) {
    isCreating.value = false;
    editingType.value = type;
    draftKey.value = type.key;
    draftLabel.value = type.label;
    draftIcon.value = type.icon ?? '';
    draftColor.value = type.color ?? '';
    draftFields.value = (type.field_schema ?? []).map((f) => ({
        key: f.key,
        label: f.label,
        type: f.type,
        required: f.required ?? false,
        optionsText: (f.options ?? []).join(', '),
    }));
    errorMessage.value = null;
}

function backToList() {
    isCreating.value = false;
    editingType.value = null;
    errorMessage.value = null;
}

function addField() {
    draftFields.value.push({
        key: '',
        label: '',
        type: 'text',
        required: false,
        optionsText: '',
    });
}

function removeField(index: number) {
    draftFields.value = draftFields.value.filter((_, i) => i !== index);
}

async function saveType() {
    if (!draftKey.value.trim() || !draftLabel.value.trim()) {
        return;
    }

    saving.value = true;
    errorMessage.value = null;

    const field_schema: NodeTypeFieldSchema[] = draftFields.value
        .filter((f) => f.key.trim() && f.label.trim())
        .map((f) => ({
            key: f.key.trim(),
            label: f.label.trim(),
            type: f.type,
            required: f.required,
            options:
                f.type === 'select' || f.type === 'multiselect'
                    ? f.optionsText
                          .split(',')
                          .map((o) => o.trim())
                          .filter(Boolean)
                    : undefined,
        }));

    const payload = {
        key: draftKey.value.trim(),
        label: draftLabel.value.trim(),
        icon: draftIcon.value.trim() || null,
        color: draftColor.value || null,
        field_schema,
    };

    try {
        const url = editingType.value
            ? `/api/spaces/${props.spaceId}/node-types/${editingType.value.id}`
            : `/api/spaces/${props.spaceId}/node-types`;

        const res = await apiFetch(url, {
            method: editingType.value ? 'PUT' : 'POST',
            body: JSON.stringify(payload),
        });

        if (!res.ok) {
            const data = await res.json();
            errorMessage.value =
                (
                    Object.values(data.errors ?? {})[0] as string[] | undefined
                )?.at(0) ??
                data.message ??
                null;

            return;
        }

        emit('changed');
        backToList();
    } catch (err) {
        console.error('Failed to save node type:', err);
    } finally {
        saving.value = false;
    }
}

async function deleteType(type: NodeTypeEntry) {
    if (!confirm(t.value.confirmDeleteNodeType)) {
        return;
    }

    try {
        await apiFetch(`/api/spaces/${props.spaceId}/node-types/${type.id}`, {
            method: 'DELETE',
        });
        emit('changed');
    } catch (err) {
        console.error('Failed to delete node type:', err);
    }
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 flex max-h-[90vh] w-full max-w-lg flex-col rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between p-6 pb-4">
                <div class="flex items-center gap-2.5">
                    <button
                        v-if="isCreating || editingType"
                        @click="backToList"
                        class="rounded-xl p-1.5 text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                    >
                        <ArrowLeft class="h-4 w-4" />
                    </button>
                    <div
                        v-else
                        class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <Layers class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.nodeTypesManagerAction }}
                        </h3>
                        <p
                            v-if="!isCreating && !editingType"
                            class="text-xs text-slate-400"
                        >
                            {{ t.nodeTypesManagerHint }}
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

            <!-- Список типов -->
            <div
                v-if="!isCreating && !editingType"
                class="min-h-0 flex-1 space-y-2 overflow-y-auto px-6 pb-4 text-xs"
            >
                <p
                    v-if="!nodeTypes.length"
                    class="rounded-xl border border-dashed border-slate-700 px-3 py-4 text-center text-slate-500"
                >
                    {{ t.noNodeTypesEmpty }}
                </p>
                <div
                    v-for="type in nodeTypes"
                    :key="type.id"
                    class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/60 px-3 py-2"
                >
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-sm"
                        :style="{
                            backgroundColor: `${type.color ?? '#3b82f6'}22`,
                            color: type.color ?? '#3b82f6',
                        }"
                    >
                        {{ type.icon || type.label.charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-slate-100">
                            {{ type.label }}
                        </p>
                        <p class="truncate text-[10px] text-slate-500">
                            {{ type.key }} ·
                            {{ (type.field_schema ?? []).length }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="startEdit(type)"
                        :title="t.editAction"
                        class="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-slate-200"
                    >
                        <Pencil class="h-3.5 w-3.5" />
                    </button>
                    <button
                        type="button"
                        @click="deleteType(type)"
                        :title="t.deleteAction"
                        class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-rose-950/40 hover:text-rose-400"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>

                <button
                    type="button"
                    @click="startCreate"
                    class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-600 px-3 py-2 font-semibold text-slate-300 transition-colors hover:border-blue-500 hover:text-blue-300"
                >
                    <Plus class="h-3.5 w-3.5" />
                    <span>{{ t.newNodeTypeAction }}</span>
                </button>
            </div>

            <!-- Форма создания/редактирования -->
            <div
                v-else
                class="min-h-0 flex-1 space-y-3.5 overflow-y-auto px-6 pb-2 text-xs"
            >
                <p v-if="errorMessage" class="text-rose-400">
                    {{ errorMessage }}
                </p>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="mb-1 block font-semibold text-slate-300"
                            >{{ t.nodeTypeLabelField }} *</label
                        >
                        <input
                            v-model="draftLabel"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-semibold text-slate-300"
                            >{{ t.nodeTypeKeyLabel }} *</label
                        >
                        <input
                            v-model="draftKey"
                            type="text"
                            required
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                </div>
                <p class="-mt-2 text-[10px] text-slate-500">
                    {{ t.nodeTypeKeyHint }}
                </p>

                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.nodeTypeIconLabel
                    }}</label>
                    <input
                        v-model="draftIcon"
                        type="text"
                        maxlength="4"
                        class="w-24 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label class="mb-1.5 block font-semibold text-slate-300">{{
                        t.colorLabel
                    }}</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="c in presetColors"
                            :key="c"
                            type="button"
                            @click="draftColor = c"
                            class="h-6 w-6 rounded-full border border-white/20 transition-transform active:scale-95"
                            :class="{
                                'ring-2 ring-white ring-offset-2 ring-offset-slate-900':
                                    draftColor === c,
                            }"
                            :style="{ backgroundColor: c }"
                        />
                        <button
                            type="button"
                            @click="draftColor = ''"
                            class="rounded-lg border border-slate-700 bg-slate-800 px-2 py-1 text-[10px] text-slate-400 hover:text-slate-200"
                        >
                            {{ t.colorAuto }}
                        </button>
                    </div>
                </div>

                <div class="border-t border-slate-800 pt-3">
                    <label class="mb-2 block font-semibold text-slate-300">{{
                        t.fieldsSectionLabel
                    }}</label>

                    <div
                        v-for="(field, index) in draftFields"
                        :key="index"
                        class="mb-2 space-y-2 rounded-xl border border-slate-700 bg-slate-800/60 p-2.5"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                v-model="field.label"
                                type="text"
                                :placeholder="t.fieldLabelLabel"
                                class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            />
                            <input
                                v-model="field.key"
                                type="text"
                                :placeholder="t.fieldKeyLabel"
                                class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            />
                            <button
                                type="button"
                                @click="removeField(index)"
                                :title="t.removeFieldAction"
                                class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-rose-950/40 hover:text-rose-400"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <select
                                v-model="field.type"
                                class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            >
                                <option
                                    v-for="kind in fieldKinds"
                                    :key="kind.id"
                                    :value="kind.id"
                                >
                                    {{ t[kind.labelKey] }}
                                </option>
                            </select>
                            <label
                                class="flex shrink-0 items-center gap-1.5 text-[11px] text-slate-300"
                            >
                                <input
                                    v-model="field.required"
                                    type="checkbox"
                                    class="h-3.5 w-3.5 rounded border-slate-600 bg-slate-900 text-blue-600 focus:ring-blue-500"
                                />
                                {{ t.fieldRequiredLabel }}
                            </label>
                        </div>
                        <input
                            v-if="
                                field.type === 'select' ||
                                field.type === 'multiselect'
                            "
                            v-model="field.optionsText"
                            type="text"
                            :placeholder="t.fieldOptionsLabel"
                            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>

                    <button
                        type="button"
                        @click="addField"
                        class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-600 px-3 py-2 font-semibold text-slate-300 transition-colors hover:border-blue-500 hover:text-blue-300"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        <span>{{ t.addFieldAction }}</span>
                    </button>
                </div>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 p-6 pt-3"
            >
                <button
                    @click="
                        isCreating || editingType ? backToList() : emit('close')
                    "
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    v-if="isCreating || editingType"
                    :disabled="saving"
                    @click="saveType"
                    class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500 disabled:opacity-50"
                >
                    {{ t.saveAction }}
                </button>
            </div>
        </div>
    </div>
</template>
