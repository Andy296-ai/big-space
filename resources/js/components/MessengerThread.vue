<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    Copy,
    Download,
    Eye,
    File as FileIcon,
    Link2,
    Loader2,
    Paperclip,
    Pencil,
    Pin,
    PinOff,
    Search,
    Send,
    SmilePlus,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { apiFetch, getCsrfToken } from '../lib/api';
import { getEcho } from '../lib/echo';
import { useT } from '../lib/i18n';
import type { RecordResult } from '../lib/useMediaRecorder';
import { useMentionAutocomplete } from '../lib/useMentionAutocomplete';
import type { MentionCandidate } from '../lib/useMentionAutocomplete';
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

export interface ReactionEntry {
    emoji: string;
    count: number;
    reacted_by_me: boolean;
}

/** @упоминание человека — считается заново на каждый ответ сервера, не персистится (см. ResolvesUserMentions). */
export interface UserMentionEntry {
    id: number;
    name: string;
}

export interface MessageEntry {
    id: number;
    type: 'text' | 'voice' | 'video' | 'file';
    body: string | null;
    created_at: string;
    edited_at: string | null;
    deleted_at: string | null;
    pinned_at: string | null;
    sender: { id: number; name: string } | null;
    attachment: MessageAttachmentEntry | null;
    node_references: NodeReferenceEntry[];
    reactions: ReactionEntry[];
    user_mentions: UserMentionEntry[];
}

/** Тот же фиксированный набор, что и в MessageController::ALLOWED_REACTION_EMOJIS — не пикер, просто быстрый тап. */
const QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '🙏', '🎉'];

type BodySegment =
    | { kind: 'text'; text: string }
    | { kind: 'node-mention'; ref: NodeReferenceEntry }
    | { kind: 'user-mention'; ref: UserMentionEntry };

/** Изображения показываем миниатюрой в самом пузыре — остальное превью только по клику (Eye). */
const IMAGE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

const MENTION_PATTERN = /\[\[(node|user):(\d+)\]\]/g;

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

/**
 * "Прилипание" к низу — пока читатель следит за свежими сообщениями,
 * message.posted (шлётся на пост/правку/удаление/пин/реакцию — на любую
 * активность в разговоре) обновляет вид как раньше. Если он прокрутил
 * вверх к истории, load() полностью заменил бы messages.value последним
 * окном и принудительно скроллил вниз — то есть чужая реакция или пин
 * где угодно в разговоре выдёргивала бы читателя обратно вниз и роняла
 * бы уже подгруженную через loadOlder() старую историю. Вместо этого
 * копим факт пропущенного обновления и подтягиваем его при возврате к низу.
 */
const stickToBottom = ref(true);
let missedUpdateWhileScrolledUp = false;
const newBody = ref('');
const posting = ref(false);
const listEl = ref<HTMLDivElement | null>(null);

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const uploadProgress = ref<number | null>(null);
const uploadError = ref('');
const previewingMessage = ref<MessageEntry | null>(null);

const mentionCandidates = ref<MentionCandidate[]>([]);
const mention = useMentionAutocomplete(mentionCandidates);
const composerEl = ref<HTMLTextAreaElement | null>(null);

const pinnedMessages = ref<MessageEntry[]>([]);
const pinnedBannerOpen = ref(false);
const flashMessageId = ref<number | null>(null);
const jumpingToMessage = ref(false);

const reactionPickerId = ref<number | null>(null);

const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref<MessageEntry[]>([]);
const searching = ref(false);
const searched = ref(false);
const searchInputEl = ref<HTMLInputElement | null>(null);
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

interface ReadByEntry {
    id: number;
    name: string;
}

const lastReadBy = ref<ReadByEntry[]>([]);

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

/**
 * Читает read-by только для СВОЕГО последнего сообщения в текущем окне —
 * не по каждому историческому, дорого не по деньгам, а по числу запросов
 * на пустом месте. Без broadcast-обновления — освежается заодно с любым
 * поводом перезагрузить/докрутить тред (см. вызовы после scrollToBottom()).
 */
async function refreshReadReceipt() {
    const last = messages.value[messages.value.length - 1];

    if (!last || !isMine(last) || isDeletedPlaceholder(last)) {
        lastReadBy.value = [];

        return;
    }

    try {
        const res = await apiFetch(`${baseUrl()}/messages/${last.id}/read-by`);
        lastReadBy.value = await res.json();
    } catch (err) {
        console.error('Failed to load read receipts:', err);
    }
}

function formatReadByNames(readers: ReadByEntry[]): string {
    const names = readers.map((r) => r.name);

    if (names.length <= 3) {
        return names.join(', ');
    }

    return `${names.slice(0, 3).join(', ')} +${names.length - 3}`;
}

async function loadPinned() {
    try {
        const res = await apiFetch(`${baseUrl()}/messages/pinned`);
        pinnedMessages.value = await res.json();
    } catch (err) {
        console.error('Failed to load pinned messages:', err);
    }
}

/**
 * Одним запросом: сообщения, закреплённые, участники (кандидаты на
 * @упоминание) и "прочитано" под своим последним — плюс отметка "прочитано"
 * самим фактом открытия, как отдельный /read раньше. Было 5 отдельных
 * запросов (messages + markRead + pinned + participants + read-by),
 * параллельных или нет — обычный браузер параллельность ещё терпит, а вот
 * WebView в десктопном Tauri-клиенте ограничивает число одновременных
 * соединений к одному хосту заметно жёстче, и лишние запросы там просто
 * вставали в очередь друг за другом. Один запрос убирает зависимость от
 * этого лимита клиента совсем — см. MessageController::bootstrap().
 */
async function load() {
    loading.value = true;
    hasMoreOlder.value = true;

    try {
        const res = await apiFetch(`${baseUrl()}/bootstrap`);
        const data = await res.json();
        messages.value = data.messages ?? [];
        pinnedMessages.value = data.pinned ?? [];
        mentionCandidates.value = data.participants ?? [];
        lastReadBy.value = data.read_by ?? [];
        await scrollToBottom();
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
    if (!listEl.value) {
        return;
    }

    if (listEl.value.scrollTop < 80) {
        loadOlder();
    }

    const distanceFromBottom =
        listEl.value.scrollHeight -
        listEl.value.scrollTop -
        listEl.value.clientHeight;
    const nowAtBottom = distanceFromBottom < 120;

    if (nowAtBottom && !stickToBottom.value && missedUpdateWhileScrolledUp) {
        missedUpdateWhileScrolledUp = false;
        load();
    }

    stickToBottom.value = nowAtBottom;
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
        // Не await — это лишь "прочитано кем" под собственным последним
        // сообщением, не критично для завершения отправки, а на
        // однопоточном dev-сервере лишний последовательный запрос здесь
        // только продлевает ощущение "зависания".
        refreshReadReceipt();
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
        // Не await — это лишь "прочитано кем" под собственным последним
        // сообщением, не критично для завершения отправки, а на
        // однопоточном dev-сервере лишний последовательный запрос здесь
        // только продлевает ощущение "зависания".
        refreshReadReceipt();
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
        // Не await — это лишь "прочитано кем" под собственным последним
        // сообщением, не критично для завершения отправки, а на
        // однопоточном dev-сервере лишний последовательный запрос здесь
        // только продлевает ощущение "зависания".
        refreshReadReceipt();
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

/** Закреплять может любой участник — маленький внутренний инструмент без роли модератора. */
function canPinMessage(message: MessageEntry): boolean {
    return message.deleted_at === null;
}

/**
 * Реагировать/копировать текст удалённого сообщения бессмысленно — но
 * isDeletedPlaceholder() тут не подходит: для root'а она false (сервер
 * оставляет ему body/attachment тобстоуна, см.
 * MessageController::serialize()), из-за чего вся панель действий
 * рендерилась и позволяла root'у реагировать на/копировать уже удалённое
 * сообщение. Проверяем deleted_at напрямую, как остальные canX.
 */
function canActOnMessage(message: MessageEntry): boolean {
    return message.deleted_at === null;
}

/** Режет тело на текст и карточки упоминаний [[node:ID]]/[[user:ID]] — оба уже отфильтрованы сервером. */
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

        const id = Number(match[2]);
        let segment: BodySegment | null = null;

        if (match[1] === 'node') {
            const ref = message.node_references.find((r) => r.id === id);
            segment = ref ? { kind: 'node-mention', ref } : null;
        } else {
            const ref = message.user_mentions.find((r) => r.id === id);
            segment = ref ? { kind: 'user-mention', ref } : null;
        }

        segments.push(segment ?? { kind: 'text', text: match[0] });

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
                pinned_at: null,
                body: isRoot.value ? current.body : null,
                attachment: isRoot.value ? current.attachment : null,
            };
        }

        // Сервер сам открепляет удалённое сообщение — синхронизируем баннер.
        pinnedMessages.value = pinnedMessages.value.filter(
            (m) => m.id !== message.id,
        );
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

async function togglePin(message: MessageEntry) {
    try {
        const res = await apiFetch(`${baseUrl()}/messages/${message.id}/pin`, {
            method: 'POST',
        });

        if (!res.ok) {
            throw new Error('pin toggle failed');
        }

        const updated: MessageEntry = await res.json();
        const index = messages.value.findIndex((m) => m.id === message.id);

        if (index !== -1) {
            messages.value[index] = updated;
        }

        await loadPinned();
    } catch (err) {
        console.error('Failed to toggle pin:', err);
    } finally {
        activeActionsId.value = null;
    }
}

async function toggleReaction(message: MessageEntry, emoji: string) {
    reactionPickerId.value = null;

    try {
        const res = await apiFetch(
            `${baseUrl()}/messages/${message.id}/reactions`,
            {
                method: 'POST',
                body: JSON.stringify({ emoji }),
            },
        );

        if (!res.ok) {
            throw new Error('reaction toggle failed');
        }

        const updated: MessageEntry = await res.json();
        const index = messages.value.findIndex((m) => m.id === message.id);

        if (index !== -1) {
            messages.value[index] = updated;
        }
    } catch (err) {
        console.error('Failed to toggle reaction:', err);
    }
}

function toggleReactionPicker(message: MessageEntry) {
    reactionPickerId.value =
        reactionPickerId.value === message.id ? null : message.id;
}

/**
 * Прыжок к сообщению из баннера закреплённых или из поиска: если оно уже
 * в загруженном окне — просто скроллим; иначе одним запросом подгружаем
 * окно вокруг него (?around_id=, см. MessageController::messagesAround)
 * и заменяем текущее окно им — дальнейшая прокрутка вверх/вниз продолжает
 * подгружать историю от новой точки как обычно.
 */
async function jumpToMessage(id: number) {
    if (jumpingToMessage.value) {
        return;
    }

    jumpingToMessage.value = true;
    pinnedBannerOpen.value = false;
    searchOpen.value = false;

    try {
        if (!messages.value.some((m) => m.id === id)) {
            const res = await apiFetch(`${baseUrl()}/messages?around_id=${id}`);
            messages.value = await res.json();
            hasMoreOlder.value = true;
            // Последнее сообщение в новом окне — почти наверняка не то же,
            // для которого lastReadBy был запрошен изначально: без сброса
            // плашка "прочитано X" привязалась бы к чужому историческому
            // сообщению просто потому, что оно оказалось последним в этом
            // окне (см. lastMessageId).
            lastReadBy.value = [];
        }

        await nextTick();
        const el = listEl.value?.querySelector<HTMLElement>(
            `[data-message-id="${id}"]`,
        );
        el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        flashMessageId.value = id;
        setTimeout(() => {
            if (flashMessageId.value === id) {
                flashMessageId.value = null;
            }
        }, 1600);
    } catch (err) {
        console.error('Failed to jump to message:', err);
    } finally {
        jumpingToMessage.value = false;
    }
}

function onSearchInput() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    const trimmed = searchQuery.value.trim();

    if (trimmed.length < 2) {
        searchResults.value = [];
        searched.value = false;

        return;
    }

    searchDebounceTimer = setTimeout(async () => {
        searching.value = true;

        try {
            const res = await apiFetch(
                `${baseUrl()}/messages/search?q=${encodeURIComponent(trimmed)}`,
            );
            searchResults.value = await res.json();
        } catch (err) {
            console.error('Failed to search messages:', err);
        } finally {
            searching.value = false;
            searched.value = true;
        }
    }, 250);
}

async function onComposerInput() {
    if (!composerEl.value) {
        return;
    }

    mention.handleInput(
        newBody.value,
        composerEl.value.selectionStart ?? newBody.value.length,
    );
}

async function selectMention(candidate: MentionCandidate) {
    if (!composerEl.value) {
        return;
    }

    const result = mention.applyMention(newBody.value, candidate);
    newBody.value = result.text;

    await nextTick();
    composerEl.value?.focus();
    composerEl.value?.setSelectionRange(result.cursor, result.cursor);
}

function onComposerKeydown(event: KeyboardEvent) {
    if (mention.query.value !== null && mention.matches.value.length) {
        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            selectMention(mention.matches.value[0]);

            return;
        }

        if (event.key === 'Escape') {
            mention.close();

            return;
        }
    }

    const noModifiers =
        !event.ctrlKey && !event.shiftKey && !event.altKey && !event.metaKey;

    if (event.key === 'Enter' && noModifiers) {
        event.preventDefault();
        post();
    }
}

/** Небольшая задержка — иначе blur успевает закрыть список раньше, чем зарегистрируется клик по кандидату. */
function onComposerBlur() {
    setTimeout(() => mention.close(), 150);
}

async function toggleSearch() {
    searchOpen.value = !searchOpen.value;

    if (searchOpen.value) {
        await nextTick();
        searchInputEl.value?.focus();
    } else {
        searchQuery.value = '';
        searchResults.value = [];
        searched.value = false;
    }
}

const canPost = computed(() => newBody.value.trim().length > 0);

const lastMessageId = computed(
    () => messages.value[messages.value.length - 1]?.id ?? null,
);

const currentUserId = computed(() => props.currentUserId);
const channelName = computed(() => `App.Models.User.${currentUserId.value}`);

function handleMessagePosted(payload: { conversation_id: number }) {
    if (payload.conversation_id !== props.conversationId) {
        return;
    }

    if (stickToBottom.value) {
        load();
    } else {
        missedUpdateWhileScrolledUp = true;
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
            class="flex shrink-0 items-center gap-2 border-b border-slate-800 px-3 py-2"
        >
            <div
                v-if="searchOpen"
                class="flex flex-1 items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/60 px-2.5 py-1.5"
            >
                <Search class="h-3.5 w-3.5 shrink-0 text-slate-500" />
                <input
                    ref="searchInputEl"
                    v-model="searchQuery"
                    type="text"
                    :placeholder="t.messengerSearchPlaceholder"
                    @input="onSearchInput"
                    class="w-full bg-transparent text-xs text-slate-100 placeholder-slate-500 focus:outline-none"
                />
                <Loader2
                    v-if="searching"
                    class="h-3.5 w-3.5 shrink-0 animate-spin text-slate-500"
                />
            </div>
            <div v-else class="flex-1" />
            <button
                type="button"
                @click="toggleSearch"
                :aria-label="t.messengerSearchAction"
                :title="t.messengerSearchAction"
                class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
            >
                <X v-if="searchOpen" class="h-4 w-4" />
                <Search v-else class="h-4 w-4" />
            </button>
        </div>

        <div
            v-if="searchOpen && searched"
            class="max-h-56 shrink-0 overflow-y-auto border-b border-slate-800 bg-slate-900/60"
        >
            <p
                v-if="!searchResults.length"
                class="px-4 py-3 text-center text-[11px] text-slate-500"
            >
                {{ t.messengerSearchNoResults }}
            </p>
            <button
                v-for="result in searchResults"
                :key="result.id"
                type="button"
                :disabled="jumpingToMessage"
                @click="jumpToMessage(result.id)"
                class="flex w-full flex-col rounded-lg px-4 py-1.5 text-start transition-colors hover:bg-slate-800/60 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span class="text-[10px] font-bold text-sky-400">{{
                    result.sender?.name ?? t.deletedAuthorLabel
                }}</span>
                <span class="truncate text-[11px] text-slate-300">{{
                    result.body
                }}</span>
            </button>
        </div>

        <div
            v-if="pinnedMessages.length"
            class="shrink-0 border-b border-slate-800 bg-slate-900/60"
        >
            <button
                type="button"
                @click="pinnedBannerOpen = !pinnedBannerOpen"
                class="flex w-full items-center gap-2 px-4 py-2 text-start transition-colors hover:bg-slate-800/40"
            >
                <Pin class="h-3.5 w-3.5 shrink-0 text-amber-400" />
                <span
                    class="min-w-0 flex-1 truncate text-[11px] font-semibold text-slate-300"
                >
                    {{ t.messengerPinnedSectionTitle }} ({{
                        pinnedMessages.length
                    }})
                </span>
                <ChevronUp
                    v-if="pinnedBannerOpen"
                    class="h-3.5 w-3.5 shrink-0 text-slate-500"
                />
                <ChevronDown
                    v-else
                    class="h-3.5 w-3.5 shrink-0 text-slate-500"
                />
            </button>
            <div
                v-if="pinnedBannerOpen"
                class="max-h-40 space-y-0.5 overflow-y-auto px-2 pb-2"
            >
                <button
                    v-for="pinned in pinnedMessages"
                    :key="pinned.id"
                    type="button"
                    :disabled="jumpingToMessage"
                    @click="jumpToMessage(pinned.id)"
                    class="flex w-full flex-col rounded-lg px-2.5 py-1.5 text-start transition-colors hover:bg-slate-800/60 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span class="text-[10px] font-bold text-sky-400">{{
                        pinned.sender?.name ?? t.deletedAuthorLabel
                    }}</span>
                    <span class="truncate text-[11px] text-slate-300">
                        {{
                            pinned.type === 'text'
                                ? (pinned.body ?? '')
                                : t.messengerAttachmentPreviewLabel
                        }}
                    </span>
                </button>
            </div>
        </div>

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
                    :data-message-id="message.id"
                    :class="[
                        'group flex max-w-[78%] gap-2 rounded-2xl transition-colors',
                        isMine(message) ? 'ms-auto flex-row-reverse' : '',
                        flashMessageId === message.id
                            ? 'bg-amber-500/10 ring-1 ring-amber-400/50'
                            : '',
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
                                        v-if="segment.kind === 'node-mention'"
                                        type="button"
                                        @click.stop="goToNode(segment.ref)"
                                        class="mx-0.5 inline-flex items-center gap-1 rounded-lg border border-white/20 bg-black/15 px-1.5 py-0.5 align-middle text-[11px] font-semibold hover:bg-black/25"
                                    >
                                        <Link2 class="h-3 w-3 shrink-0" />
                                        <span class="max-w-32 truncate">{{
                                            segment.ref.title || t.untitledNode
                                        }}</span>
                                    </button>
                                    <span
                                        v-else-if="
                                            segment.kind === 'user-mention'
                                        "
                                        class="mx-0.5 rounded-lg bg-black/15 px-1.5 py-0.5 align-middle text-[11px] font-semibold"
                                    >
                                        @{{ segment.ref.name }}
                                    </span>
                                    <template v-else>{{
                                        segment.text
                                    }}</template>
                                </template>
                            </template>

                            <template v-else-if="isPlayableMedia(message)">
                                <!-- preload="none" — иначе каждое голосовое/видео в
                                     окне начинает буферизацию сразу при открытии
                                     треда, а не по требованию: при нескольких
                                     таких сообщениях разом это лишняя параллельная
                                     нагрузка на клиента без всякой пользы, пока
                                     ничего из них ещё не собираются проигрывать. -->
                                <audio
                                    v-if="message.type === 'voice'"
                                    :src="previewUrlFor(message)"
                                    controls
                                    preload="none"
                                    class="h-9 w-56 max-w-full"
                                />
                                <video
                                    v-else
                                    :src="previewUrlFor(message)"
                                    controls
                                    preload="none"
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

                        <!-- Пилюли реакций — под пузырём, кликабельны (повторный тап снимает свою реакцию). -->
                        <div
                            v-if="message.reactions.length"
                            :class="[
                                'flex flex-wrap items-center gap-1 px-1',
                                isMine(message) ? 'justify-end' : '',
                            ]"
                        >
                            <button
                                v-for="reaction in message.reactions"
                                :key="reaction.emoji"
                                type="button"
                                @click.stop="
                                    toggleReaction(message, reaction.emoji)
                                "
                                :class="[
                                    'flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10.5px] transition-colors',
                                    reaction.reacted_by_me
                                        ? 'border-blue-500/60 bg-blue-500/15 text-blue-300'
                                        : 'border-slate-700 bg-slate-800/60 text-slate-400 hover:bg-slate-800',
                                ]"
                            >
                                <span>{{ reaction.emoji }}</span>
                                <span class="font-semibold">{{
                                    reaction.count
                                }}</span>
                            </button>
                        </div>

                        <div
                            :class="[
                                'flex items-center gap-1.5 px-1',
                                isMine(message)
                                    ? 'flex-row-reverse self-end'
                                    : '',
                            ]"
                        >
                            <span
                                class="flex items-center gap-1 text-[9.5px] text-slate-500"
                            >
                                <Pin
                                    v-if="message.pinned_at"
                                    class="h-2.5 w-2.5 shrink-0 text-amber-400"
                                />
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
                                    'relative flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100',
                                    activeActionsId === message.id
                                        ? 'opacity-100'
                                        : '',
                                ]"
                            >
                                <button
                                    v-if="canActOnMessage(message)"
                                    type="button"
                                    @click.stop="toggleReactionPicker(message)"
                                    :aria-label="t.messengerReactAction"
                                    :title="t.messengerReactAction"
                                    class="rounded p-0.5 text-slate-500 hover:text-slate-200"
                                >
                                    <SmilePlus class="h-3 w-3" />
                                </button>
                                <div
                                    v-if="reactionPickerId === message.id"
                                    @click.stop
                                    :class="[
                                        'absolute bottom-full z-10 mb-1 flex items-center gap-0.5 rounded-xl border border-slate-700 bg-slate-800 p-1 shadow-lg',
                                        isMine(message) ? 'end-0' : 'start-0',
                                    ]"
                                >
                                    <button
                                        v-for="emoji in QUICK_REACTIONS"
                                        :key="emoji"
                                        type="button"
                                        @click.stop="
                                            toggleReaction(message, emoji)
                                        "
                                        class="rounded-lg p-1 text-sm transition-colors hover:bg-slate-700"
                                    >
                                        {{ emoji }}
                                    </button>
                                </div>
                                <button
                                    v-if="
                                        message.body && canActOnMessage(message)
                                    "
                                    type="button"
                                    @click.stop="copyMessageText(message)"
                                    :aria-label="t.messengerCopyAction"
                                    :title="t.messengerCopyAction"
                                    class="rounded p-0.5 text-slate-500 hover:text-slate-200"
                                >
                                    <Copy class="h-3 w-3" />
                                </button>
                                <button
                                    v-if="canPinMessage(message)"
                                    type="button"
                                    @click.stop="togglePin(message)"
                                    :aria-label="
                                        message.pinned_at
                                            ? t.messengerUnpinAction
                                            : t.messengerPinAction
                                    "
                                    :title="
                                        message.pinned_at
                                            ? t.messengerUnpinAction
                                            : t.messengerPinAction
                                    "
                                    :class="[
                                        'rounded p-0.5',
                                        message.pinned_at
                                            ? 'text-amber-400 hover:text-amber-300'
                                            : 'text-slate-500 hover:text-slate-200',
                                    ]"
                                >
                                    <PinOff
                                        v-if="message.pinned_at"
                                        class="h-3 w-3"
                                    />
                                    <Pin v-else class="h-3 w-3" />
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

                        <!-- «Прочитано» — только под своим последним сообщением в окне, без live-обновления. -->
                        <span
                            v-if="
                                message.id === lastMessageId &&
                                isMine(message) &&
                                lastReadBy.length > 0
                            "
                            class="px-1 text-end text-[9px] text-slate-500"
                        >
                            {{ t.messengerSeenByLabel }}
                            {{ formatReadByNames(lastReadBy) }}
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
            <div class="relative min-h-0 flex-1">
                <div
                    v-if="
                        mention.query.value !== null &&
                        mention.matches.value.length
                    "
                    class="absolute bottom-full z-10 mb-1 w-full overflow-hidden rounded-xl border border-slate-700 bg-slate-800 shadow-lg"
                >
                    <button
                        v-for="candidate in mention.matches.value"
                        :key="candidate.id"
                        type="button"
                        @click="selectMention(candidate)"
                        class="flex w-full items-center px-3 py-1.5 text-start text-xs text-slate-200 transition-colors hover:bg-slate-700"
                    >
                        @{{ candidate.name }}
                    </button>
                </div>
                <textarea
                    ref="composerEl"
                    v-model="newBody"
                    :placeholder="t.messengerComposerPlaceholder"
                    rows="1"
                    maxlength="2000"
                    @input="onComposerInput"
                    @keydown="onComposerKeydown"
                    @blur="onComposerBlur"
                    class="min-h-0 w-full resize-none rounded-xl border border-slate-700 bg-slate-800/60 px-3 py-2 text-xs text-slate-100 placeholder-slate-500 focus:border-blue-500 focus:outline-none"
                />
            </div>
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
