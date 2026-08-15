<script setup lang="ts">
import { Globe2, Loader2, Plus, Search, Users, X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { getEcho } from '../lib/echo';
import { useT } from '../lib/i18n';
import MessengerThread from './MessengerThread.vue';

interface ConversationSummary {
    id: number;
    type: 'global' | 'team' | 'direct';
    team: { id: number; name: string } | null;
    direct_with: { id: number; name: string } | null;
    unread_count: number;
    last_message: {
        id: number;
        type: string;
        body: string | null;
        sender_name: string | null;
        created_at: string;
    } | null;
}

interface Teammate {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    currentUserId: number;
    onlineUserIds: Set<number>;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'unread-count', count: number): void;
}>();

const t = useT();

const loading = ref(true);
const conversations = ref<ConversationSummary[]>([]);
const selectedId = ref<number | null>(null);

const showStartDirect = ref(false);
const teammates = ref<Teammate[]>([]);
const teammateQuery = ref('');
const startingDirectWith = ref<number | null>(null);

const globalConversation = computed(() =>
    conversations.value.find((c) => c.type === 'global'),
);
const teamConversations = computed(() =>
    conversations.value.filter((c) => c.type === 'team'),
);
const directConversations = computed(() =>
    conversations.value.filter((c) => c.type === 'direct'),
);

const filteredTeammates = computed(() => {
    const q = teammateQuery.value.trim().toLowerCase();
    const existingDirectUserIds = new Set(
        directConversations.value.map((c) => c.direct_with?.id),
    );

    return teammates.value.filter(
        (m) =>
            !existingDirectUserIds.has(m.id) &&
            (q === '' || m.name.toLowerCase().includes(q)),
    );
});

function previewFor(conversation: ConversationSummary): string {
    if (!conversation.last_message) {
        return t.value.messengerNoMessagesYet;
    }

    const prefix = conversation.last_message.sender_name
        ? `${conversation.last_message.sender_name}: `
        : '';

    // У файловых/голосовых/видео-сообщений body всегда null — показываем
    // подпись по типу вместо пустой строки.
    const body =
        conversation.last_message.type === 'text'
            ? (conversation.last_message.body ?? '')
            : t.value.messengerAttachmentPreviewLabel;

    return `${prefix}${body}`;
}

async function loadSummary() {
    loading.value = true;

    try {
        const res = await apiFetch('/api/messenger/summary');
        const data = await res.json();
        conversations.value = data.conversations ?? [];
        emit('unread-count', data.total_unread ?? 0);
    } catch (err) {
        console.error('Failed to load messenger summary:', err);
    } finally {
        loading.value = false;
    }
}

function selectConversation(id: number) {
    selectedId.value = id;
    showStartDirect.value = false;
}

async function openStartDirect() {
    showStartDirect.value = true;
    teammateQuery.value = '';

    try {
        const res = await apiFetch('/api/messenger/teammates');
        teammates.value = await res.json();
    } catch (err) {
        console.error('Failed to load teammates:', err);
    }
}

async function startDirect(userId: number) {
    if (startingDirectWith.value !== null) {
        return;
    }

    startingDirectWith.value = userId;

    try {
        const res = await apiFetch(`/api/messenger/direct/${userId}`, {
            method: 'POST',
        });
        const data = await res.json();
        await loadSummary();
        selectConversation(data.id);
    } catch (err) {
        console.error('Failed to start a direct conversation:', err);
    } finally {
        startingDirectWith.value = null;
    }
}

// Пока панель открыта, любое новое сообщение (в любом разговоре) обновляет
// превью и бейджи в рейле — сам активный тред отдельно обновляет себя через
// MessengerThread. Канал общий с NotificationBell/HudOverlay-бейджем —
// снимаем только свой обработчик при закрытии панели.
const channelName = `App.Models.User.${props.currentUserId}`;

function handleMessagePosted() {
    loadSummary();
}

onMounted(() => {
    loadSummary();
    getEcho()
        .private(channelName)
        .listen('.message.posted', handleMessagePosted);
});

onUnmounted(() => {
    getEcho()
        .private(channelName)
        .stopListening('.message.posted', handleMessagePosted);
});
</script>

<template>
    <div
        class="animate-in fade-in slide-in-from-right-4 pointer-events-auto absolute top-20 right-4 bottom-6 z-20 flex w-[640px] max-w-[92vw] overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900/90 text-slate-100 shadow-2xl backdrop-blur-xl duration-200"
    >
        <!-- Левая колонка: список разговоров -->
        <div
            class="flex w-[230px] shrink-0 flex-col border-e border-slate-800 bg-slate-950/40"
        >
            <div class="flex items-center justify-between gap-2 px-4 py-3.5">
                <h3 class="text-sm font-bold text-slate-100">
                    {{ t.messengerTitle }}
                </h3>
                <button
                    @click="emit('close')"
                    :aria-label="t.close"
                    class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
                <div
                    v-if="loading"
                    class="flex justify-center py-6 text-slate-500"
                >
                    <Loader2 class="h-4 w-4 animate-spin" />
                </div>

                <template v-else>
                    <div
                        class="px-2 pt-3 pb-1 text-[9.5px] font-extrabold tracking-wider text-slate-500 uppercase"
                    >
                        {{ t.messengerGlobalSection }}
                    </div>
                    <button
                        v-if="globalConversation"
                        type="button"
                        @click="selectConversation(globalConversation.id)"
                        :class="[
                            'flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-start transition-colors',
                            selectedId === globalConversation.id
                                ? 'bg-blue-600/20 text-slate-100'
                                : 'text-slate-400 hover:bg-slate-800/60',
                        ]"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-blue-600 text-white"
                        >
                            <Globe2 class="h-3.5 w-3.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-bold">{{
                                t.messengerGlobalChat
                            }}</span>
                            <span
                                class="block truncate text-[10.5px] text-slate-500"
                                >{{ previewFor(globalConversation) }}</span
                            >
                        </span>
                        <span
                            v-if="globalConversation.unread_count > 0"
                            class="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-rose-500 px-1 text-[9.5px] font-bold text-white"
                        >
                            {{
                                globalConversation.unread_count > 9
                                    ? '9+'
                                    : globalConversation.unread_count
                            }}
                        </span>
                    </button>

                    <div
                        class="px-2 pt-3 pb-1 text-[9.5px] font-extrabold tracking-wider text-slate-500 uppercase"
                    >
                        {{ t.messengerTeamsSection }}
                    </div>
                    <p
                        v-if="!teamConversations.length"
                        class="px-2 py-1 text-[10.5px] text-slate-600"
                    >
                        {{ t.messengerNoTeams }}
                    </p>
                    <button
                        v-for="c in teamConversations"
                        :key="c.id"
                        type="button"
                        @click="selectConversation(c.id)"
                        :class="[
                            'flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-start transition-colors',
                            selectedId === c.id
                                ? 'bg-blue-600/20 text-slate-100'
                                : 'text-slate-400 hover:bg-slate-800/60',
                        ]"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-sky-500 to-sky-700 text-[10.5px] font-bold text-white"
                        >
                            {{
                                (c.team?.name ?? '?').slice(0, 1).toUpperCase()
                            }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-bold">{{
                                c.team?.name
                            }}</span>
                            <span
                                class="block truncate text-[10.5px] text-slate-500"
                                >{{ previewFor(c) }}</span
                            >
                        </span>
                        <span
                            v-if="c.unread_count > 0"
                            class="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-rose-500 px-1 text-[9.5px] font-bold text-white"
                        >
                            {{ c.unread_count > 9 ? '9+' : c.unread_count }}
                        </span>
                    </button>

                    <div
                        class="flex items-center justify-between px-2 pt-3 pb-1"
                    >
                        <span
                            class="text-[9.5px] font-extrabold tracking-wider text-slate-500 uppercase"
                        >
                            {{ t.messengerDirectSection }}
                        </span>
                        <button
                            @click="openStartDirect"
                            :title="t.messengerStartDirectAction"
                            class="rounded-lg p-1 text-slate-500 transition-colors hover:bg-slate-800 hover:text-slate-200"
                        >
                            <Plus class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <p
                        v-if="!directConversations.length"
                        class="px-2 py-1 text-[10.5px] text-slate-600"
                    >
                        {{ t.messengerNoDirect }}
                    </p>
                    <button
                        v-for="c in directConversations"
                        :key="c.id"
                        type="button"
                        @click="selectConversation(c.id)"
                        :class="[
                            'flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-start transition-colors',
                            selectedId === c.id
                                ? 'bg-blue-600/20 text-slate-100'
                                : 'text-slate-400 hover:bg-slate-800/60',
                        ]"
                    >
                        <span
                            class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-[10.5px] font-bold text-white"
                        >
                            {{
                                (c.direct_with?.name ?? '?')
                                    .slice(0, 2)
                                    .toUpperCase()
                            }}
                            <span
                                v-if="
                                    c.direct_with &&
                                    onlineUserIds.has(c.direct_with.id)
                                "
                                class="absolute -right-0.5 -bottom-0.5 h-2.5 w-2.5 rounded-full border-2 border-slate-900 bg-emerald-400"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-bold">{{
                                c.direct_with?.name
                            }}</span>
                            <span
                                class="block truncate text-[10.5px] text-slate-500"
                                >{{ previewFor(c) }}</span
                            >
                        </span>
                        <span
                            v-if="c.unread_count > 0"
                            class="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-rose-500 px-1 text-[9.5px] font-bold text-white"
                        >
                            {{ c.unread_count > 9 ? '9+' : c.unread_count }}
                        </span>
                    </button>
                </template>
            </div>
        </div>

        <!-- Правая часть: активный тред, либо панель выбора собеседника -->
        <div class="flex min-h-0 flex-1 flex-col">
            <template v-if="showStartDirect">
                <div
                    class="flex items-center gap-2 border-b border-slate-800 px-4 py-3.5"
                >
                    <Users class="h-4 w-4 text-slate-400" />
                    <h4 class="text-xs font-bold text-slate-200">
                        {{ t.messengerStartDirectAction }}
                    </h4>
                </div>
                <div class="border-b border-slate-800 p-3">
                    <div
                        class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/60 px-3 py-2"
                    >
                        <Search class="h-3.5 w-3.5 text-slate-500" />
                        <input
                            v-model="teammateQuery"
                            type="text"
                            :placeholder="t.messengerSearchTeammates"
                            class="w-full bg-transparent text-xs text-slate-100 placeholder-slate-500 focus:outline-none"
                        />
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <p
                        v-if="!filteredTeammates.length"
                        class="px-2 py-6 text-center text-xs text-slate-500"
                    >
                        {{ t.messengerNoTeammatesFound }}
                    </p>
                    <button
                        v-for="m in filteredTeammates"
                        :key="m.id"
                        type="button"
                        :disabled="startingDirectWith !== null"
                        @click="startDirect(m.id)"
                        class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-start text-slate-300 transition-colors hover:bg-slate-800/60 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span
                            class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-[10.5px] font-bold text-white"
                        >
                            {{ m.name.slice(0, 2).toUpperCase() }}
                            <span
                                v-if="onlineUserIds.has(m.id)"
                                class="absolute -right-0.5 -bottom-0.5 h-2.5 w-2.5 rounded-full border-2 border-slate-900 bg-emerald-400"
                            />
                        </span>
                        <span
                            class="min-w-0 flex-1 truncate text-xs font-semibold"
                            >{{ m.name }}</span
                        >
                        <Loader2
                            v-if="startingDirectWith === m.id"
                            class="h-3.5 w-3.5 shrink-0 animate-spin text-slate-400"
                        />
                    </button>
                </div>
            </template>

            <MessengerThread
                v-else-if="selectedId !== null"
                :key="selectedId"
                :conversation-id="selectedId"
                :current-user-id="props.currentUserId"
            />

            <div
                v-else
                class="flex flex-1 items-center justify-center px-6 text-center text-xs text-slate-500"
            >
                {{ t.messengerSelectConversation }}
            </div>
        </div>
    </div>
</template>
