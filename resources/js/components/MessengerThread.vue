<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    Copy,
    Download,
    Eye,
    File as FileIcon,
    Link2,
    Loader2,
    Paperclip,
    Pencil,
    Send,
    Trash2,
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

/** Карточка узла, на который ссылается [[node:ID]] в теле — уже отфильтрована сервером по доступу читателя. */
export interface NodeReferenceEntry {
    id: number;
    title: string;
    space_id: number;
    space_slug: string;
}

export interface MessageEntry {
    id: number;
    type: 'text' | 'voice' | 'video' | 'file';
    body: string | null;
    created_at: string;
    edited_at: string | null;
    deleted_at: string | null;
    sender: { id: number; name: string } | null;
    attachment: MessageAttachmentEntry | null;
    node_references: NodeReferenceEntry[];
}

type BodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; ref: NodeReferenceEntry };

/** Изображения показываем миниатюрой в самом пузыре — остальное превью только по клику (Eye). */
const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

const MENTION_PATTERN = /\[\[node:(\d+)\]\]/g;

const props = defineProps<{
    conversationId: number;
    currentUserId: number;
    currentSpaceId: number;
}>();

const emit = defineEmits<{
    (e: 'focus-node', nodeId: number): void;
}>();

const t = useT();

const isRoot = computed(() => usePage().props.auth.user.is_root);

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

/** Плашка "сообщение удалено": сервер уже прячет body/attachment для не-root — см. MessageController::serialize(). */
function isDeletedPlaceholder(message: MessageEntry): boolean {
    return (
        message.deleted_at !== null &&
        message.body === null &&
        message.attachment === null
    );
}

function canEditMessage(message: MessageEntry): boolean {
    return (
        isMine(message) &&
        message.type === 'text' &&
        message.deleted_at === null
    );
}

function canDeleteMessage(message: MessageEntry): boolean {
    return (isMine(message) || isRoot.value) && message.deleted_at === null;
}

/** Режет тело на текст и карточки упоминаний [[node:ID]] — ссылки уже отфильтрованы сервером по доступу читателя. */
function splitBody(message: MessageEntry): BodySegment[] {
    if (!message.body) {
        return [];
    }

    const segments: BodySegment[] = [];
    let lastIndex = 0;

    for (const match of message.body.matchAll(MENTION_PATTERN)) {
        const index = match.index ?? 0;

        if (index > lastIndex) {
            segments.push({
                kind: 'text',
                text: message.body.slice(lastIndex, index),
            });
        }

        const ref = message.node_references.find(
            (r) => r.id === Number(match[1]),
        );
        segments.push(
            ref ? { kind: 'mention', ref } : { kind: 'text', text: match[0] },
        );

        lastIndex = index + match[0].length;
    }

    if (lastIndex < message.body.length) {
        segments.push({ kind: 'text', text: message.body.slice(lastIndex) });
    }

    return segments;
}

/** То же переключение "своё/чужое пространство", что и у поиска в HudOverlay — см. GraphController::index(). */
function goToNode(ref: NodeReferenceEntry) {
    if (ref.space_id === props.currentSpaceId) {
        emit('focus-node', ref.id);
    } else {
        router.get('/', { space: ref.space_slug, focus: ref.id });
    }
}

async function copyMessageText(message: MessageEntry) {
    if (!message.body) {
        return;
    }

    try {
        await navigator.clipboard.writeText(message.body);
    } catch (err) {
        console.error('Failed to copy message text:', err);
    }
}

const editingMessageId = ref<number | null>(null);
const editingBody = ref('');
const savingEdit = ref(false);

function startEdit(message: MessageEntry) {
    editingMessageId.value = message.id;
    editingBody.value = message.body ?? '';
    activeActionsId.value = null;
}

function cancelEdit() {
    editingMessageId.value = null;
    editingBody.value = '';
}

async function saveEdit(message: MessageEntry) {
    const body = editingBody.value.trim();

    if (!body || savingEdit.value) {
        return;
    }

    savingEdit.value = true;

    try {
        const res = await apiFetch(`${baseUrl()}/messages/${message.id}`, {
            method: 'PUT',
            body: JSON.stringify({ body }),
        });

        if (!res.ok) {
            throw new Error('edit failed');
        }

        const updated: MessageEntry = await res.json();
        const index = messages.value.findIndex((m) => m.id === message.id);

        if (index !== -1) {
            messages.value[index] = updated;
        }

        cancelEdit();
    } catch (err) {
        console.error('Failed to edit message:', err);
    } finally {
        savingEdit.value = false;
    }
}

// Тап/ховер разворачивает иконки действий; удаление — двухшаговое (взвод +
// подтверждение по той же кнопке), тот же приём, что и в TeamManagerModal.vue.
const activeActionsId = ref<number | null>(null);
const confirmingDeleteId = ref<number | null>(null);

function toggleActions(message: MessageEntry) {
    activeActionsId.value =
        activeActionsId.value === message.id ? null : message.id;
}

async function deleteMessage(message: MessageEntry) {
    try {
        const res = await apiFetch(`${baseUrl()}/messages/${message.id}`, {
            method: 'DELETE',
        });

        if (!res.ok) {
            throw new Error('delete failed');
        }

        // Тот же сигнал (message.posted) придёт и перезагрузит список сам —
        // но правим на месте сразу, не дожидаясь лишнего круга.
        const index = messages.value.findIndex((m) => m.id === message.id);

        if (index !== -1) {
            const current = messages.value[index];
            messages.value[index] = {
                ...current,
                deleted_at: new Date().toISOString(),
                body: isRoot.value ? current.body : null,
                attachment: isRoot.value ? current.attachment : null,
            };
        }
    } catch (err) {
        console.error('Failed to delete message:', err);
    } finally {
        confirmingDeleteId.value = null;
        activeActionsId.value = null;
    }
}

function handleDeleteClick(message: MessageEntry) {
    if (confirmingDeleteId.value === message.id) {
        deleteMessage(message);
    } else {
        confirmingDeleteId.value = message.id;
    }
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
                        'group flex max-w-[78%] gap-2',
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

                        <!-- Плашка удалённого сообщения -->
                        <div
                            v-if="isDeletedPlaceholder(message)"
                            :class="[
                                'rounded-2xl bg-slate-800/40 px-3 py-2 text-xs text-slate-500 italic',
                                isMine(message)
                                    ? 'rounded-ee-md'
                                    : 'rounded-es-md',
                            ]"
                        >
                            {{ t.messengerDeletedPlaceholder }}
                        </div>

                        <!-- Режим редактирования -->
                        <div
                            v-else-if="editingMessageId === message.id"
                            class="flex flex-col gap-1.5"
                        >
                            <textarea
                                v-model="editingBody"
                                rows="2"
                                maxlength="2000"
                                @keydown.enter.exact.prevent="saveEdit(message)"
                                @keydown.escape="cancelEdit"
                                class="min-w-56 rounded-2xl border border-blue-500 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:outline-none"
                            />
                            <div
                                class="flex items-center gap-3 px-1 text-[10px] font-semibold"
                            >
                                <button
                                    type="button"
                                    :disabled="savingEdit"
                                    @click="saveEdit(message)"
                                    class="text-blue-400 hover:text-blue-300 disabled:opacity-50"
                                >
                                    {{
                                        savingEdit
                                            ? t.savingAction
                                            : t.saveAction
                                    }}
                                </button>
                                <button
                                    type="button"
                                    @click="cancelEdit"
                                    class="text-slate-500 hover:text-slate-300"
                                >
                                    {{ t.cancelAction }}
                                </button>
                            </div>
                        </div>

                        <!-- Обычный пузырь -->
                        <div
                            v-else
                            @click="toggleActions(message)"
                            :class="[
                                'cursor-pointer rounded-2xl px-3 py-2 text-xs leading-relaxed whitespace-pre-wrap',
                                isMine(message)
                                    ? 'rounded-ee-md bg-blue-600 text-white'
                                    : 'rounded-es-md bg-slate-800 text-slate-100',
                            ]"
                        >
                            <template v-if="message.type === 'text'">
                                <template
                                    v-for="(segment, i) in splitBody(message)"
                                    :key="i"
                                >
                                    <button
                                        v-if="segment.kind === 'mention'"
                                        type="button"
                                        @click.stop="goToNode(segment.ref)"
                                        class="mx-0.5 inline-flex items-center gap-1 rounded-lg border border-white/20 bg-black/15 px-1.5 py-0.5 align-middle text-[11px] font-semibold hover:bg-black/25"
                                    >
                                        <Link2 class="h-3 w-3 shrink-0" />
                                        <span class="max-w-32 truncate">{{
                                            segment.ref.title || t.untitledNode
                                        }}</span>
                                    </button>
                                    <template v-else>{{
                                        segment.text
                                    }}</template>
                                </template>
                            </template>

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
                                    @click.stop="openPreview(message)"
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
                                        @click.stop="openPreview(message)"
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
                                        @click.stop
                                        class="shrink-0 rounded p-1 opacity-70 transition-opacity hover:opacity-100"
                                    >
                                        <Download class="h-4 w-4" />
                                    </a>
                                </div>
                            </template>
                        </div>

                        <div
                            :class="[
                                'flex items-center gap-1.5 px-1',
                                isMine(message)
                                    ? 'flex-row-reverse self-end'
                                    : '',
                            ]"
                        >
                            <span class="text-[9.5px] text-slate-500">
                                {{ formatTime(message.created_at) }}
                                <template v-if="message.edited_at">
                                    · {{ t.messengerEditedLabel }}</template
                                >
                                <template v-if="message.deleted_at && isRoot">
                                    · {{ t.messengerDeletedLabel }}</template
                                >
                            </span>

                            <div
                                v-if="
                                    !isDeletedPlaceholder(message) &&
                                    editingMessageId !== message.id
                                "
                                :class="[
                                    'flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100',
                                    activeActionsId === message.id
                                        ? 'opacity-100'
                                        : '',
                                ]"
                            >
                                <button
                                    v-if="message.body"
                                    type="button"
                                    @click.stop="copyMessageText(message)"
                                    :aria-label="t.messengerCopyAction"
                                    :title="t.messengerCopyAction"
                                    class="rounded p-0.5 text-slate-500 hover:text-slate-200"
                                >
                                    <Copy class="h-3 w-3" />
                                </button>
                                <button
                                    v-if="canEditMessage(message)"
                                    type="button"
                                    @click.stop="startEdit(message)"
                                    :aria-label="t.editAction"
                                    :title="t.editAction"
                                    class="rounded p-0.5 text-slate-500 hover:text-slate-200"
                                >
                                    <Pencil class="h-3 w-3" />
                                </button>
                                <button
                                    v-if="canDeleteMessage(message)"
                                    type="button"
                                    @click.stop="handleDeleteClick(message)"
                                    :aria-label="
                                        confirmingDeleteId === message.id
                                            ? t.confirmDelete
                                            : t.deleteAction
                                    "
                                    :title="
                                        confirmingDeleteId === message.id
                                            ? t.confirmDelete
                                            : t.deleteAction
                                    "
                                    :class="[
                                        'rounded p-0.5',
                                        confirmingDeleteId === message.id
                                            ? 'text-rose-400'
                                            : 'text-slate-500 hover:text-rose-400',
                                    ]"
                                >
                                    <Trash2 class="h-3 w-3" />
                                </button>
                            </div>
                        </div>
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
