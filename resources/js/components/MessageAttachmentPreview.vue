<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useT } from '../lib/i18n';

export interface AttachmentPreviewData {
    id: number;
    label: string;
    badge: string;
    previewable: boolean;
}

const props = defineProps<{
    conversationId: number;
    messageId: number;
    attachment: AttachmentPreviewData;
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
const isHtml = computed(
    () => format.value === 'html' || format.value === 'htm',
);

const previewUrl = computed(
    () =>
        `/api/messenger/conversations/${props.conversationId}/messages/${props.messageId}/attachment/preview`,
);

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex h-[88vh] w-[92vw] max-w-4xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-semibold">
                            {{ attachment.label }}
                        </span>
                        <span
                            class="shrink-0 rounded-md bg-slate-800 px-1.5 py-0.5 text-[10px] font-bold text-slate-400"
                        >
                            {{ attachment.badge }}
                        </span>
                    </div>
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

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

                    <!--
                        HTML — только в песочнице: без allow-scripts вообще (ничего не
                        исполняется, независимо от содержимого) и никогда вместе с
                        allow-same-origin (та комбинация — классический sandbox-escape).
                        allow-popups — чтобы работали обычные target="_blank" ссылки
                        внутри вложенной страницы, для "как в вебе"-ощущения без риска
                        исполнения. Сервер тем же ответом отдельно ставит
                        Content-Security-Policy: sandbox — см.
                        MessageAttachmentController::preview().
                    -->
                    <iframe
                        v-else-if="isHtml"
                        :src="previewUrl"
                        :title="attachment.label"
                        sandbox="allow-popups"
                        referrerpolicy="no-referrer"
                        class="h-full w-full border-0 bg-white"
                    />
                </div>
            </div>
        </div>
    </Teleport>
</template>
