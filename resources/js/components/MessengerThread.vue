<script setup lang="ts">
import {
    Download,
    Eye,
    File as FileIcon,
    Loader2,
    Paperclip,
    Send,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { apiFetch, getCsrfToken } from '../lib/api';
import { getEcho } from '../lib/echo';
import { useT } from '../lib/i18n';
import type { RecordResult } from '../lib/useMediaRecorder';
import MessageAttachmentPreview from './MessageAttachmentPreview.vue';
import VideoRecorderButton from './VideoRecorderButton.vue';
import VoiceRecorderButton from './VoiceRecorderButton.vue';

export interface MessageAttachmentEntry {
    id: number;
    label: string;
    size: number;
    format: string;
    mime: string;
    badge: string;
    previewable: boolean;
    duration_ms: number | null;
}

export interface MessageEntry {
    id: number;
    type: 'text' | 'voice' | 'video' | 'file';
    body: string | null;
    created_at: string;
    sender: { id: number; name: string } | null;
    attachment: MessageAttachmentEntry | null;
}

/** Изображения показываем миниатюрой в самом пузыре — остальное превью только по клику (Eye). */
const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

const props = defineProps<{
    conversationId: number;
    currentUserId: number;
}>();

const t = useT();

const loading = ref(true);
const loadingOlder = ref(false);
const hasMoreOlder = ref(true);
const messages = ref<MessageEntry[]>([]);
const newBody = ref('');
const posting = ref(false);
const listEl = ref<HTMLDivElement | null>(null);

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadProgress = ref<number | null>(null);
const uploadError = ref('');
const previewingMessage = ref<MessageEntry | null>(null);

function baseUrl(): string {
    return `/api/messenger/conversations/${props.conversationId}`;
}

function previewUrlFor(message: MessageEntry): string {
    return `${baseUrl()}/messages/${message.id}/attachment/preview`;
}

function downloadUrlFor(message: MessageEntry): string {
    return `${baseUrl()}/messages/${message.id}/attachment/download`;
}

function isImageAttachment(message: MessageEntry): boolean {
    return (
        message.attachment !== null &&
        IMAGE_FORMATS.includes(message.attachment.badge.toLowerCase())
    );
}

/** Голосовые/видео-сообщения всегда рендерятся встроенным плеером, а не карточкой файла. */
function isPlayableMedia(message: MessageEntry): boolean {
    return (
        (message.type === 'voice' || message.type === 'video') &&
        message.attachment !== null
    );
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function openPreview(message: MessageEntry) {
    if (message.attachment?.previewable) {
        previewingMessage.value = message;
    }
}

async function scrollToBottom() {
    await nextTick();
    listEl.value?.scrollTo({ top: listEl.value.scrollHeight });
}

async function markRead() {
    try {
        await apiFetch(`${baseUrl()}/read`, { method: 'POST' });
    } catch (err) {
        console.error('Failed to mark conversation as read:', err);
    }
}

async function load() {
    loading.value = true;
    hasMoreOlder.value = true;

    try {
        const res = await apiFetch(`${baseUrl()}/messages`);
        messages.value = await res.json();
        await scrollToBottom();
        await markRead();
    } catch (err) {
        console.error('Failed to load messages:', err);
    } finally {
        loading.value = false;
    }
}

/** Подгружает более старые сообщения при прокрутке к самому верху списка. */
async function loadOlder() {
    if (loadingOlder.value || !hasMoreOlder.value || !messages.value.length) {
        return;
    }

    loadingOlder.value = true;
    const beforeId = messages.value[0].id;
    const previousHeight = listEl.value?.scrollHeight ?? 0;

    try {
        const res = await apiFetch(
            `${baseUrl()}/messages?before_id=${beforeId}`,
        );
        const older: MessageEntry[] = await res.json();

        if (!older.length) {
            hasMoreOlder.value = false;
        } else {
            messages.value = [...older, ...messages.value];
            // Держим прокрутку на том же сообщении, а не прыгаем к верху.
            await nextTick();

            if (listEl.value) {
                listEl.value.scrollTop =
                    listEl.value.scrollHeight - previousHeight;
            }
        }
    } catch (err) {
        console.error('Failed to load older messages:', err);
    } finally {
        loadingOlder.value = false;
    }
}

function onScroll() {
    if (listEl.value && listEl.value.scrollTop < 80) {
        loadOlder();
    }
}

async function post() {
    const body = newBody.value.trim();

    if (!body || posting.value) {
        return;
    }

    posting.value = true;

    try {
        const res = await apiFetch(`${baseUrl()}/messages`, {
            method: 'POST',
            body: JSON.stringify({ body }),
        });
        messages.value.push(await res.json());
        newBody.value = '';
        await scrollToBottom();
    } catch (err) {
        console.error('Failed to post message:', err);
    } finally {
        posting.value = false;
    }
}

function pickFile() {
    fileInput.value?.click();
}

async function onFileChosen(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ''; // чтобы тот же файл можно было выбрать снова

    if (!file || uploading.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    const form = new FormData();
    form.append('type', 'file');
    form.append('file', file);

    try {
        const res = await apiFetch(`${baseUrl()}/messages`, {
            method: 'POST',
            body: form,
        });

        if (!res.ok) {
            throw new Error('upload failed');
        }

        messages.value.push(await res.json());
        await scrollToBottom();
    } catch (err) {
        console.error('Failed to send the attachment:', err);
        uploadError.value = t.value.messengerUploadError;
    } finally {
        uploading.value = false;
    }
}

/**
 * XMLHttpRequest вместо apiFetch: только у него есть событие upload-прогресса
 * (fetch его не даёт) — нужно исключительно для видео-сообщений, самых
 * тяжёлых загрузок в мессенджере.
 */
function xhrUpload(url: string, form: FormData): Promise<Response> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        const token = getCsrfToken();

        if (token) {
            xhr.setRequestHeader('X-XSRF-TOKEN', token);
        }

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                uploadProgress.value = Math.round(
                    (event.loaded / event.total) * 100,
                );
            }
        };

        xhr.onload = () => {
            resolve(
                new Response(xhr.responseText, {
                    status: xhr.status,
                    statusText: xhr.statusText,
                }),
            );
        };
        xhr.onerror = () => reject(new Error('network error'));

        xhr.send(form);
    });
}

async function onMediaRecorded(type: 'voice' | 'video', payload: RecordResult) {
    if (uploading.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = '';

    if (type === 'video') {
        uploadProgress.value = 0;
    }

    const extension = payload.mimeType.includes('mp4') ? 'mp4' : 'webm';
    const file = new File([payload.blob], `${type}-message.${extension}`, {
        type: payload.mimeType,
    });

    const form = new FormData();
    form.append('type', type);
    form.append('file', file);
    form.append('duration_ms', String(Math.round(payload.durationMs)));

    try {
        const res =
            type === 'video'
                ? await xhrUpload(`${baseUrl()}/messages`, form)
                : await apiFetch(`${baseUrl()}/messages`, {
                      method: 'POST',
                      body: form,
                  });

        if (!res.ok) {
            throw new Error('upload failed');
        }

        messages.value.push(await res.json());
        await scrollToBottom();
    } catch (err) {
        console.error(`Failed to send the ${type} message:`, err);
        uploadError.value = t.value.messengerUploadError;
    } finally {
        uploading.value = false;
        uploadProgress.value = null;
    }
}

function onRecorderError() {
    uploadError.value = t.value.messengerMediaPermissionError;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function isMine(message: MessageEntry): boolean {
    return message.sender?.id === props.currentUserId;
}

const canPost = computed(() => newBody.value.trim().length > 0);

const currentUserId = computed(() => props.currentUserId);
const channelName = computed(() => `App.Models.User.${currentUserId.value}`);

function handleMessagePosted(payload: { conversation_id: number }) {
    if (payload.conversation_id === props.conversationId) {
        load();
    }
}

onMounted(() => {
    load();
    getEcho()
        .private(channelName.value)
        .listen('.message.posted', handleMessagePosted);
});

// Канал на пользователя общий и держится открытым другими компонентами
// (NotificationBell и т.п.) — снимаем при размонтировании только свой
// обработчик, не сам канал.
onUnmounted(() => {
    getEcho()
        .private(channelName.value)
        .stopListening('.message.posted', handleMessagePosted);
});

watch(
    () => props.conversationId,
    (_next, previous) => {
        if (previous !== undefined) {
            load();
        }
    },
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            ref="listEl"
            class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
            @scroll="onScroll"
        >
            <div
                v-if="loading"
                class="flex h-full items-center justify-center text-slate-400"
            >
                <Loader2 class="h-5 w-5 animate-spin" />
            </div>
            <div
                v-else-if="!messages.length"
                class="flex h-full items-center justify-center text-xs text-slate-500"
            >
                {{ t.messengerEmptyThread }}
            </div>
            <template v-else>
                <div
                    v-if="loadingOlder"
                    class="flex justify-center py-1 text-slate-500"
                >
                    <Loader2 class="h-4 w-4 animate-spin" />
                </div>
                <div
                    v-for="message in messages"
                    :key="message.id"
                    :class="[
                        'flex max-w-[78%] gap-2',
                        isMine(message) ? 'ms-auto flex-row-reverse' : '',
                    ]"
                >
                    <div class="flex flex-col gap-0.5">
                        <span
                            v-if="!isMine(message)"
                            class="px-1 text-[10.5px] font-bold text-sky-400"
                        >
                            {{ message.sender?.name ?? t.deletedAuthorLabel }}
                        </span>
                        <div
                            :class="[
                                'rounded-2xl px-3 py-2 text-xs leading-relaxed whitespace-pre-wrap',
                                isMine(message)
                                    ? 'rounded-ee-md bg-blue-600 text-white'
                                    : 'rounded-es-md bg-slate-800 text-slate-100',
                            ]"
                        >
                            <template v-if="message.type === 'text'">{{
                                message.body
                            }}</template>

                            <template v-else-if="isPlayableMedia(message)">
                                <audio
                                    v-if="message.type === 'voice'"
                                    :src="previewUrlFor(message)"
                                    controls
                                    class="h-9 w-56 max-w-full"
                                />
                                <video
                                    v-else
                                    :src="previewUrlFor(message)"
                                    controls
                                    class="max-h-56 max-w-full rounded-lg"
                                />
                            </template>

                            <template v-else-if="message.attachment">
                                <img
                                    v-if="isImageAttachment(message)"
                                    :src="previewUrlFor(message)"
                                    :alt="message.attachment.label"
                                    @click="openPreview(message)"
                                    class="max-h-56 max-w-full cursor-pointer rounded-lg object-contain"
                                />
                                <div v-else class="flex items-center gap-2">
                                    <FileIcon
                                        class="h-5 w-5 shrink-0 opacity-80"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-semibold">
                                            {{ message.attachment.label }}
                                        </div>
                                        <div class="text-[10px] opacity-70">
                                            {{ message.attachment.badge }} ·
                                            {{
                                                formatSize(
                                                    message.attachment.size,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <button
                                        v-if="message.attachment.previewable"
                                        type="button"
                                        @click="openPreview(message)"
                                        :aria-label="t.previewAction"
                                        :title="t.previewAction"
                                        class="shrink-0 rounded p-1 opacity-70 transition-opacity hover:opacity-100"
                                    >
                                        <Eye class="h-4 w-4" />
                                    </button>
                                    <a
                                        :href="downloadUrlFor(message)"
                                        :aria-label="t.download"
                                        :title="t.download"
                                        class="shrink-0 rounded p-1 opacity-70 transition-opacity hover:opacity-100"
                                    >
                                        <Download class="h-4 w-4" />
                                    </a>
                                </div>
                            </template>
                        </div>
                        <span
                            :class="[
                                'px-1 text-[9.5px] text-slate-500',
                                isMine(message) ? 'self-end' : '',
                            ]"
                        >
                            {{ formatTime(message.created_at) }}
                        </span>
                    </div>
                </div>
            </template>
        </div>

        <p
            v-if="uploadError"
            class="border-t border-slate-800 bg-rose-950/30 px-4 py-1.5 text-[10.5px] text-rose-400"
        >
            {{ uploadError }}
        </p>

        <div
            v-if="uploadProgress !== null"
            class="h-0.5 border-t border-slate-800 bg-slate-800"
        >
            <div
                class="h-full bg-blue-500 transition-all"
                :style="{ width: `${uploadProgress}%` }"
            />
        </div>

        <div class="flex items-end gap-2 border-t border-slate-800 p-3">
            <button
                :disabled="uploading"
                @click="pickFile"
                :aria-label="t.messengerAttachAction"
                :title="t.messengerAttachAction"
                class="flex shrink-0 items-center justify-center rounded-xl border border-slate-700 bg-slate-800/60 p-2.5 text-slate-300 transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Loader2 v-if="uploading" class="h-4 w-4 animate-spin" />
                <Paperclip v-else class="h-4 w-4" />
            </button>
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                @change="onFileChosen"
            />
            <VoiceRecorderButton
                @recorded="onMediaRecorded('voice', $event)"
                @error="onRecorderError"
            />
            <VideoRecorderButton
                @recorded="onMediaRecorded('video', $event)"
                @error="onRecorderError"
            />
            <textarea
                v-model="newBody"
                :placeholder="t.messengerComposerPlaceholder"
                rows="1"
                maxlength="2000"
                @keydown.enter.exact.prevent="post"
                class="min-h-0 flex-1 resize-none rounded-xl border border-slate-700 bg-slate-800/60 px-3 py-2 text-xs text-slate-100 placeholder-slate-500 focus:border-blue-500 focus:outline-none"
            />
            <button
                :disabled="!canPost || posting"
                @click="post"
                :aria-label="t.messengerSendAction"
                :title="t.messengerSendAction"
                class="flex shrink-0 items-center justify-center rounded-xl bg-blue-600 p-2.5 text-white shadow transition-all hover:bg-blue-500 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
            >
                <Loader2 v-if="posting" class="h-4 w-4 animate-spin" />
                <Send v-else class="h-4 w-4" />
            </button>
        </div>

        <MessageAttachmentPreview
            v-if="previewingMessage?.attachment"
            :conversation-id="conversationId"
            :message-id="previewingMessage.id"
            :attachment="previewingMessage.attachment"
            @close="previewingMessage = null"
        />
    </div>
</template>
