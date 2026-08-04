<script setup lang="ts">
import DOMPurify from 'dompurify';
import { AlertTriangle, Loader2, Pencil, Save, X } from 'lucide-vue-next';
import { marked } from 'marked';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import type { AttachmentData } from './SpaceScene.vue';

const props = defineProps<{
    spaceId: number;
    nodeId: number;
    attachment: AttachmentData;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const VIDEO_FORMATS = ['mp4', 'webm'];

const format = computed(() => props.attachment.badge.toLowerCase());
const isPdf = computed(() => format.value === 'pdf');
const isImage = computed(() => IMAGE_FORMATS.includes(format.value));
const isVideo = computed(() => VIDEO_FORMATS.includes(format.value));
const isMarkdown = computed(() => format.value === 'md');
const isText = computed(() => format.value === 'txt');

const baseUrl = computed(
    () =>
        `/api/spaces/${props.spaceId}/nodes/${props.nodeId}/attachments/${props.attachment.id}`,
);
const previewUrl = computed(() => `${baseUrl.value}/preview`);

const loading = ref(false);
const saving = ref(false);
const loadError = ref(false);
const editing = ref(false);
const rawContent = ref('');
const draftContent = ref('');

const renderedMarkdown = computed(() => {
    if (!isMarkdown.value) {
        return '';
    }

    const html = marked.parse(rawContent.value, { async: false }) as string;

    return DOMPurify.sanitize(html, {
        FORBID_TAGS: ['iframe', 'object', 'embed', 'style'],
        FORBID_ATTR: ['style'],
    });
});

async function loadTextContent() {
    loading.value = true;
    loadError.value = false;

    try {
        const response = await apiFetch(`${baseUrl.value}/content`);

        if (!response.ok) {
            throw new Error('failed to load');
        }

        const data = await response.json();
        rawContent.value = data.content;
        draftContent.value = data.content;
    } catch {
        loadError.value = true;
    } finally {
        loading.value = false;
    }
}

function startEditing() {
    draftContent.value = rawContent.value;
    editing.value = true;
}

function cancelEditing() {
    editing.value = false;
}

async function save() {
    saving.value = true;

    try {
        const response = await apiFetch(`${baseUrl.value}/content`, {
            method: 'PUT',
            body: JSON.stringify({ content: draftContent.value }),
        });

        if (!response.ok) {
            throw new Error('failed to save');
        }

        rawContent.value = draftContent.value;
        editing.value = false;
    } catch {
        loadError.value = true;
    } finally {
        saving.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);

    if (props.attachment.editable) {
        loadTextContent();
    }
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex h-[88vh] w-[92vw] max-w-5xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <!-- Шапка -->
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-semibold">
                            {{ attachment.label }}
                        </span>
                        <span
                            v-if="attachment.badge"
                            class="shrink-0 rounded-md bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-slate-400"
                        >
                            {{ attachment.badge }}
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <template v-if="attachment.editable && !loading">
                            <button
                                v-if="!editing"
                                type="button"
                                @click="startEditing"
                                class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700"
                            >
                                <Pencil class="h-3.5 w-3.5 text-amber-400" />
                                <span>{{ t.editAction }}</span>
                            </button>
                            <template v-else>
                                <button
                                    type="button"
                                    @click="cancelEditing"
                                    :disabled="saving"
                                    class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700 disabled:opacity-60"
                                >
                                    {{ t.cancelAction }}
                                </button>
                                <button
                                    type="button"
                                    @click="save"
                                    :disabled="saving"
                                    class="flex items-center gap-1.5 rounded-xl bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-500 disabled:opacity-60"
                                >
                                    <Loader2
                                        v-if="saving"
                                        class="h-3.5 w-3.5 animate-spin"
                                    />
                                    <Save v-else class="h-3.5 w-3.5" />
                                    <span>{{
                                        saving ? t.savingAction : t.saveAction
                                    }}</span>
                                </button>
                            </template>
                        </template>

                        <button
                            @click="emit('close')"
                            :aria-label="t.close"
                            class="rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <!-- Содержимое -->
                <div class="min-h-0 flex-1 bg-slate-950/40">
                    <iframe
                        v-if="isPdf"
                        :src="previewUrl"
                        :title="attachment.label"
                        class="h-full w-full border-0 bg-white"
                    />

                    <div
                        v-else-if="isImage"
                        class="flex h-full items-center justify-center overflow-auto p-4"
                    >
                        <img
                            :src="previewUrl"
                            :alt="attachment.label"
                            class="max-h-full max-w-full object-contain"
                        />
                    </div>

                    <div
                        v-else-if="isVideo"
                        class="flex h-full items-center justify-center p-4"
                    >
                        <video
                            :src="previewUrl"
                            controls
                            autoplay
                            class="max-h-full max-w-full"
                        />
                    </div>

                    <div
                        v-else-if="isMarkdown || isText"
                        class="h-full overflow-y-auto p-6"
                    >
                        <div
                            v-if="loading"
                            class="flex h-full items-center justify-center text-slate-400"
                        >
                            <Loader2 class="h-5 w-5 animate-spin" />
                        </div>
                        <div
                            v-else-if="loadError"
                            class="flex h-full flex-col items-center justify-center gap-2 text-rose-400"
                        >
                            <AlertTriangle class="h-5 w-5" />
                            <span class="text-xs">{{
                                t.previewErrorLabel
                            }}</span>
                        </div>
                        <textarea
                            v-else-if="editing"
                            v-model="draftContent"
                            spellcheck="false"
                            class="h-full w-full resize-none rounded-xl border border-slate-800 bg-slate-950 p-4 font-mono text-xs text-slate-100 outline-none focus:border-blue-500"
                        />
                        <div
                            v-else-if="isMarkdown"
                            class="markdown-body text-sm leading-relaxed text-slate-200"
                            v-html="renderedMarkdown"
                        />
                        <pre
                            v-else
                            class="font-mono text-xs whitespace-pre-wrap text-slate-200"
                            >{{ rawContent }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.markdown-body :deep(h1),
.markdown-body :deep(h2),
.markdown-body :deep(h3) {
    margin-top: 1.25em;
    margin-bottom: 0.5em;
    font-weight: 700;
    color: var(--color-slate-100);
}

.markdown-body :deep(h1) {
    font-size: 1.5em;
}

.markdown-body :deep(h2) {
    font-size: 1.25em;
}

.markdown-body :deep(h3) {
    font-size: 1.1em;
}

.markdown-body :deep(p) {
    margin-bottom: 0.75em;
}

.markdown-body :deep(ul),
.markdown-body :deep(ol) {
    margin-bottom: 0.75em;
    padding-left: 1.5em;
}

.markdown-body :deep(li) {
    margin-bottom: 0.25em;
}

.markdown-body :deep(ul) {
    list-style-type: disc;
}

.markdown-body :deep(ol) {
    list-style-type: decimal;
}

.markdown-body :deep(a) {
    color: var(--color-blue-400);
    text-decoration: underline;
}

.markdown-body :deep(code) {
    border-radius: 0.25em;
    background: var(--color-slate-800);
    padding: 0.1em 0.35em;
    font-size: 0.9em;
}

.markdown-body :deep(pre) {
    margin-bottom: 0.75em;
    overflow-x: auto;
    border-radius: 0.75em;
    background: var(--color-slate-800);
    padding: 0.75em 1em;
}

.markdown-body :deep(pre code) {
    background: none;
    padding: 0;
}

.markdown-body :deep(blockquote) {
    margin-bottom: 0.75em;
    border-left: 3px solid var(--color-slate-700);
    padding-left: 1em;
    color: var(--color-slate-400);
}

.markdown-body :deep(img) {
    max-width: 100%;
}
</style>
