<script setup lang="ts">
import { Link2, Paperclip, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import { useT } from '../lib/i18n';
import type { AttachmentData } from './SpaceScene.vue';

/** Файл или ссылка, которые ждут создания узла. */
export interface PendingAttachment {
    kind: 'file' | 'link';
    label: string;
    url?: string;
    file?: File;
}

defineProps<{
    /** Уже сохранённые вложения; при создании узла их нет. */
    saved?: AttachmentData[];
}>();

/** Очередь: при создании узла грузить некуда, пока он не появится. */
const pending = defineModel<PendingAttachment[]>('pending', { required: true });

const emit = defineEmits<{
    (e: 'remove-saved', attachmentId: number): void;
}>();

const t = useT();

const fileInput = ref<HTMLInputElement | null>(null);
const linkUrl = ref('');
const linkLabel = ref('');

function pickFiles() {
    fileInput.value?.click();
}

function onFilesChosen(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    // Одним присваиванием: pending — это defineModel, и per-итерационные
    // чтения pending.value внутри forEach видели бы один и тот же ещё не
    // обновлённый prop, из-за чего в очередь попадал только последний файл.
    pending.value = [
        ...pending.value,
        ...files.map((file) => ({
            kind: 'file' as const,
            label: file.name,
            file,
        })),
    ];

    input.value = ''; // чтобы тот же файл можно было выбрать снова
}

function addLink() {
    const url = linkUrl.value.trim();

    if (!url) {
        return;
    }

    pending.value = [
        ...pending.value,
        { kind: 'link', label: linkLabel.value.trim() || url, url },
    ];
    linkUrl.value = '';
    linkLabel.value = '';
}

function dropPending(index: number) {
    pending.value = pending.value.filter((_, i) => i !== index);
}
</script>

<template>
    <div>
        <label
            class="mb-1.5 flex items-center gap-1.5 font-semibold text-slate-300"
        >
            <Paperclip class="h-3.5 w-3.5 text-slate-400" />
            <span>{{ t.attachmentsLabel }}</span>
        </label>

        <!-- Уже сохранённые: убираются сразу -->
        <div
            v-if="saved?.length"
            class="mb-2 divide-y divide-slate-700/50 overflow-hidden rounded-xl border border-slate-700 bg-slate-800/60"
        >
            <div
                v-for="item in saved"
                :key="item.id"
                class="flex items-center gap-2 px-3 py-1.5"
            >
                <span class="min-w-0 flex-1 truncate text-slate-200">
                    {{ item.label || item.url }}
                </span>
                <span
                    v-if="item.badge"
                    class="shrink-0 text-[10px] font-bold text-slate-400"
                >
                    {{ item.badge }}
                </span>
                <button
                    type="button"
                    @click="emit('remove-saved', item.id)"
                    :title="t.removeAttachment"
                    :aria-label="t.removeAttachment"
                    class="shrink-0 rounded-lg p-1 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <!-- Очередь на загрузку -->
        <div v-if="pending.length" class="mb-2">
            <div
                class="divide-y divide-slate-700/50 overflow-hidden rounded-xl border border-dashed border-slate-600 bg-slate-800/40"
            >
                <div
                    v-for="(item, index) in pending"
                    :key="`${item.label}-${index}`"
                    class="flex items-center gap-2 px-3 py-1.5"
                >
                    <component
                        :is="item.kind === 'link' ? Link2 : Upload"
                        class="h-3.5 w-3.5 shrink-0 text-blue-400"
                    />
                    <span class="min-w-0 flex-1 truncate text-slate-300">
                        {{ item.label }}
                    </span>
                    <button
                        type="button"
                        @click="dropPending(index)"
                        :title="t.removeAttachment"
                        :aria-label="t.removeAttachment"
                        class="shrink-0 rounded-lg p-1 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
            <p class="mt-1 px-1 text-[10px] text-slate-500">
                {{ t.pendingUploads }}
            </p>
        </div>

        <!-- Добавление -->
        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="pickFiles"
                class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-[11px] font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <Upload class="h-3.5 w-3.5" />
                <span>{{ t.uploadFile }}</span>
            </button>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                @change="onFilesChosen"
            />
        </div>

        <div class="mt-2 flex items-center gap-2">
            <input
                v-model="linkUrl"
                type="url"
                :placeholder="t.linkUrlPlaceholder"
                :aria-label="t.addLink"
                class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                @keydown.enter.prevent="addLink"
            />
            <button
                type="button"
                @click="addLink"
                class="flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-[11px] font-semibold text-slate-200 transition-colors hover:bg-slate-700"
            >
                <Link2 class="h-3.5 w-3.5 text-blue-400" />
                <span>{{ t.addLink }}</span>
            </button>
        </div>
    </div>
</template>
