<script setup lang="ts">
import { Loader2, MessageSquare, Send, Trash2, X } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { getEcho } from '../lib/echo';
import { useT } from '../lib/i18n';
import { useMentionAutocomplete } from '../lib/useMentionAutocomplete';
import type { MentionCandidate } from '../lib/useMentionAutocomplete';
import type { NodeData } from './SpaceScene.vue';

interface UserMentionEntry {
    id: number;
    name: string;
}

interface CommentEntry {
    id: number;
    body: string;
    created_at: string;
    user: { id: number; name: string } | null;
    user_mentions: UserMentionEntry[];
}

type BodySegment =
    { kind: 'text'; text: string } | { kind: 'mention'; ref: UserMentionEntry };

const MENTION_PATTERN = /\[\[user:(\d+)\]\]/g;

const props = defineProps<{
    spaceId: number;
    node: NodeData;
    currentUserId: number;
    canModerate: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const loading = ref(true);
const comments = ref<CommentEntry[]>([]);
const newBody = ref('');
const posting = ref(false);
const deletingId = ref<number | null>(null);
const listEl = ref<HTMLDivElement | null>(null);

const mentionCandidates = ref<MentionCandidate[]>([]);
const mention = useMentionAutocomplete(mentionCandidates);
const composerEl = ref<HTMLTextAreaElement | null>(null);

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/comments`,
        );
        comments.value = await res.json();
    } catch (err) {
        console.error('Failed to load comments:', err);
    } finally {
        loading.value = false;
    }
}

/** Кандидаты на @упоминание — владелец, root'ы и те, кому расшарено пространство (см. Space::mentionableUsers()). */
async function loadMentionCandidates() {
    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/mentionable-users`,
        );
        mentionCandidates.value = await res.json();
    } catch (err) {
        console.error('Failed to load mentionable users:', err);
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

/** Режет тело на текст и @упоминания [[user:ID]] — уже отфильтрованы сервером (см. ResolvesUserMentions). */
function splitBody(comment: CommentEntry): BodySegment[] {
    const segments: BodySegment[] = [];
    let lastIndex = 0;

    for (const match of comment.body.matchAll(MENTION_PATTERN)) {
        const index = match.index ?? 0;

        if (index > lastIndex) {
            segments.push({
                kind: 'text',
                text: comment.body.slice(lastIndex, index),
            });
        }

        const ref = comment.user_mentions.find(
            (r) => r.id === Number(match[1]),
        );
        segments.push(
            ref ? { kind: 'mention', ref } : { kind: 'text', text: match[0] },
        );

        lastIndex = index + match[0].length;
    }

    if (lastIndex < comment.body.length) {
        segments.push({ kind: 'text', text: comment.body.slice(lastIndex) });
    }

    return segments;
}

function onComposerInput() {
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

async function scrollToBottom() {
    await nextTick();
    listEl.value?.scrollTo({ top: listEl.value.scrollHeight });
}

async function post() {
    const body = newBody.value.trim();

    if (!body || posting.value) {
        return;
    }

    posting.value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/comments`,
            {
                method: 'POST',
                body: JSON.stringify({ body }),
            },
        );
        comments.value.push(await res.json());
        newBody.value = '';
        await scrollToBottom();
    } catch (err) {
        console.error('Failed to post comment:', err);
    } finally {
        posting.value = false;
    }
}

function canDelete(comment: CommentEntry): boolean {
    return props.canModerate || comment.user?.id === props.currentUserId;
}

async function remove(comment: CommentEntry) {
    if (deletingId.value !== null) {
        return;
    }

    deletingId.value = comment.id;

    try {
        await apiFetch(
            `/api/spaces/${props.spaceId}/nodes/${props.node.id}/comments/${comment.id}`,
            { method: 'DELETE' },
        );
        comments.value = comments.value.filter((c) => c.id !== comment.id);
    } catch (err) {
        console.error('Failed to delete comment:', err);
    } finally {
        deletingId.value = null;
    }
}

// Живое обновление: кто-то ещё написал/удалил комментарий на этом узле —
// канал уже открыт для графа (Welcome.vue), здесь просто вешаем свой
// обработчик и снимаем его при закрытии, не трогая сам канал.
const liveChannelName = `space.${props.spaceId}`;

function handleCommentPosted(payload: { node_id: number }) {
    if (payload.node_id === props.node.id) {
        load();
    }
}

onMounted(() => {
    load();
    loadMentionCandidates();
    getEcho()
        .private(liveChannelName)
        .listen('.comment.posted', handleCommentPosted);
});

onUnmounted(() => {
    getEcho()
        .private(liveChannelName)
        .stopListening('.comment.posted', handleCommentPosted);
});

const canPost = computed(() => newBody.value.trim().length > 0);
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex h-[75dvh] w-[92vw] max-w-lg flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3.5"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div
                            class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                        >
                            <MessageSquare class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold">
                                {{ t.commentsTitle }}
                            </h3>
                            <p class="truncate text-[11px] text-slate-400">
                                {{ node.title || t.untitledNode }}
                            </p>
                        </div>
                    </div>
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div ref="listEl" class="min-h-0 flex-1 overflow-auto p-3">
                    <div
                        v-if="loading"
                        class="flex h-full items-center justify-center text-slate-400"
                    >
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </div>
                    <div
                        v-else-if="!comments.length"
                        class="flex h-full items-center justify-center text-xs text-slate-500"
                    >
                        {{ t.commentsEmpty }}
                    </div>
                    <div v-else class="space-y-1.5">
                        <div
                            v-for="comment in comments"
                            :key="comment.id"
                            class="rounded-2xl border border-slate-800 bg-slate-800/40 p-3"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 text-xs">
                                    <p
                                        class="truncate font-semibold text-slate-200"
                                    >
                                        {{
                                            comment.user?.name ??
                                            t.deletedAuthorLabel
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-slate-500"
                                    >
                                        {{ formatTime(comment.created_at) }}
                                    </p>
                                </div>
                                <button
                                    v-if="canDelete(comment)"
                                    :disabled="deletingId !== null"
                                    @click="remove(comment)"
                                    :aria-label="t.deleteCommentAction"
                                    :title="t.deleteCommentAction"
                                    class="shrink-0 rounded-lg p-1 text-slate-500 transition-colors hover:bg-rose-950/60 hover:text-rose-300 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Loader2
                                        v-if="deletingId === comment.id"
                                        class="h-3.5 w-3.5 animate-spin"
                                    />
                                    <Trash2 v-else class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <p
                                class="mt-1.5 text-xs leading-relaxed whitespace-pre-wrap text-slate-300"
                            >
                                <template
                                    v-for="(segment, i) in splitBody(comment)"
                                    :key="i"
                                >
                                    <span
                                        v-if="segment.kind === 'mention'"
                                        class="mx-0.5 rounded-lg bg-black/20 px-1.5 py-0.5 align-middle text-[11px] font-semibold"
                                    >
                                        @{{ segment.ref.name }}
                                    </span>
                                    <template v-else>{{
                                        segment.text
                                    }}</template>
                                </template>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-end gap-2 border-t border-slate-800 p-3">
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
                            :placeholder="t.commentsPlaceholder"
                            rows="2"
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
                        :aria-label="t.postCommentAction"
                        :title="t.postCommentAction"
                        class="flex shrink-0 items-center justify-center rounded-xl bg-blue-600 p-2.5 text-white shadow transition-all hover:bg-blue-500 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Loader2 v-if="posting" class="h-4 w-4 animate-spin" />
                        <Send v-else class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
