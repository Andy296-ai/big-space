<script setup lang="ts">
import { ImagePlus } from 'lucide-vue-next';
import { computed } from 'vue';
import { useT } from '../lib/i18n';
import AttachmentEditor from './AttachmentEditor.vue';
import type { PendingAttachment } from './AttachmentEditor.vue';
import RelationFieldPicker from './RelationFieldPicker.vue';
import type {
    AttachmentData,
    NodeData,
    NodeTypeFieldSchema,
} from './SpaceScene.vue';

const props = defineProps<{
    schema: NodeTypeFieldSchema[];
    spaceId: number;
    /** Для подписи уже выбранного значения relation-полей без похода на сервер. */
    allNodes?: NodeData[];
    /** Для виртуального поля "photo" — существующий логотип узла при редактировании. */
    existingLogoUrl?: string | null;
    /** Для виртуального поля "files" — уже сохранённые вложения узла. */
    savedAttachments?: AttachmentData[];
}>();

const emit = defineEmits<{
    (e: 'remove-saved-attachment', attachmentId: number): void;
}>();

/** Значения обычных (не photo/files) полей, ключ — field.key. */
const values = defineModel<Record<string, unknown>>('values', {
    required: true,
});
const logoFile = defineModel<File | null>('logoFile', { required: true });
const pendingAttachments = defineModel<PendingAttachment[]>('pending', {
    required: true,
});

const t = useT();

const logoPreviewUrl = computed(() => {
    if (logoFile.value) {
        return URL.createObjectURL(logoFile.value);
    }

    return props.existingLogoUrl ?? null;
});

function onLogoFileChange(event: Event) {
    logoFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function fieldValue(key: string): unknown {
    return values.value[key];
}

function setFieldValue(key: string, value: unknown) {
    values.value = { ...values.value, [key]: value };
}

function toggleMultiselectOption(key: string, option: string) {
    const current = Array.isArray(values.value[key])
        ? (values.value[key] as string[])
        : [];

    setFieldValue(
        key,
        current.includes(option)
            ? current.filter((o) => o !== option)
            : [...current, option],
    );
}

function relationValue(key: string): number | null {
    const raw = values.value[key];

    return typeof raw === 'number' ? raw : null;
}

function setRelationValue(key: string, value: number | null) {
    setFieldValue(key, value);
}
</script>

<template>
    <template v-for="field in schema" :key="field.key">
        <div v-if="field.type === 'photo'">
            <label class="mb-1.5 block font-semibold text-slate-300">{{
                field.label
            }}</label>
            <div class="flex items-center gap-3">
                <img
                    v-if="logoPreviewUrl"
                    :src="logoPreviewUrl"
                    alt=""
                    class="h-10 w-10 shrink-0 rounded-full border border-slate-700 object-cover"
                />
                <label
                    class="flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-slate-300 transition-colors hover:bg-slate-700"
                >
                    <ImagePlus class="h-3.5 w-3.5" />
                    <span>{{ t.uploadLogoAction }}</span>
                    <input
                        type="file"
                        accept="image/*"
                        class="hidden"
                        @change="onLogoFileChange"
                    />
                </label>
            </div>
        </div>

        <div v-else-if="field.type === 'files'">
            <AttachmentEditor
                v-model:pending="pendingAttachments"
                :saved="savedAttachments"
                @remove-saved="emit('remove-saved-attachment', $event)"
            />
        </div>

        <div v-else-if="field.type === 'relation'">
            <label class="mb-1 block font-semibold text-slate-300"
                >{{ field.label }}<span v-if="field.required"> *</span></label
            >
            <RelationFieldPicker
                :model-value="relationValue(field.key)"
                @update:model-value="setRelationValue(field.key, $event)"
                :space-id="spaceId"
                :all-nodes="allNodes"
            />
        </div>

        <div v-else-if="field.type === 'checkbox'">
            <label class="flex items-center gap-2 font-semibold text-slate-300">
                <input
                    type="checkbox"
                    :checked="fieldValue(field.key) === true"
                    @change="
                        setFieldValue(
                            field.key,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                    class="h-3.5 w-3.5 rounded border-slate-600 bg-slate-800 text-blue-600 focus:ring-blue-500"
                />
                <span>{{ field.label }}</span>
            </label>
        </div>

        <div v-else-if="field.type === 'select'">
            <label class="mb-1 block font-semibold text-slate-300"
                >{{ field.label }}<span v-if="field.required"> *</span></label
            >
            <select
                :value="(fieldValue(field.key) as string) ?? ''"
                @change="
                    setFieldValue(
                        field.key,
                        ($event.target as HTMLSelectElement).value || null,
                    )
                "
                :required="field.required"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            >
                <option value=""></option>
                <option
                    v-for="option in field.options ?? []"
                    :key="option"
                    :value="option"
                >
                    {{ option }}
                </option>
            </select>
        </div>

        <div v-else-if="field.type === 'multiselect'">
            <label class="mb-1.5 block font-semibold text-slate-300"
                >{{ field.label }}<span v-if="field.required"> *</span></label
            >
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="option in field.options ?? []"
                    :key="option"
                    type="button"
                    @click="toggleMultiselectOption(field.key, option)"
                    class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition-colors"
                    :class="
                        Array.isArray(fieldValue(field.key)) &&
                        (fieldValue(field.key) as string[]).includes(option)
                            ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                            : 'border-slate-700 bg-slate-800 text-slate-400 hover:text-slate-200'
                    "
                >
                    {{ option }}
                </button>
            </div>
        </div>

        <div v-else-if="field.type === 'textarea'">
            <label class="mb-1 block font-semibold text-slate-300"
                >{{ field.label }}<span v-if="field.required"> *</span></label
            >
            <textarea
                :value="(fieldValue(field.key) as string) ?? ''"
                @input="
                    setFieldValue(
                        field.key,
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
                :required="field.required"
                rows="3"
                class="w-full resize-none rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
        </div>

        <div v-else>
            <label class="mb-1 block font-semibold text-slate-300"
                >{{ field.label }}<span v-if="field.required"> *</span></label
            >
            <input
                :type="
                    field.type === 'number'
                        ? 'number'
                        : field.type === 'date'
                          ? 'date'
                          : field.type === 'url'
                            ? 'url'
                            : field.type === 'phone'
                              ? 'tel'
                              : 'text'
                "
                :value="(fieldValue(field.key) as string | number) ?? ''"
                @input="
                    setFieldValue(
                        field.key,
                        ($event.target as HTMLInputElement).value,
                    )
                "
                :required="field.required"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
        </div>
    </template>
</template>
