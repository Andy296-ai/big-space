<script setup lang="ts">
import {
    ChevronDown,
    ChevronRight,
    Loader2,
    Pencil,
    Plus,
    Trash2,
    UserMinus,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';

interface TeamMember {
    id: number;
    name: string;
    email: string;
}

interface TeamEntry {
    id: number;
    name: string;
    description: string;
    users_count: number;
    users: TeamMember[];
}

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const loading = ref(true);
const teams = ref<TeamEntry[]>([]);
const error = ref('');

const newName = ref('');
const newDescription = ref('');
const creating = ref(false);

const expandedId = ref<number | null>(null);
const editingId = ref<number | null>(null);
const editName = ref('');
const editDescription = ref('');
const savingEdit = ref(false);
// Одно нажатие вооружает удаление, второе (по той же кнопке) подтверждает —
// у удаления команды нет отмены (полностью сносит групповой чат и историю).
const confirmingDeleteId = ref<number | null>(null);

const memberIdentifier = ref<Record<number, string>>({});
const addingMember = ref<number | null>(null);
const memberError = ref<Record<number, string>>({});

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const res = await apiFetch('/api/admin/teams');
        teams.value = await res.json();
    } catch (err) {
        console.error('Failed to load teams:', err);
        error.value = t.value.teamManagerLoadError;
    } finally {
        loading.value = false;
    }
}

function toggleExpand(teamId: number) {
    expandedId.value = expandedId.value === teamId ? null : teamId;
}

async function createTeam() {
    const name = newName.value.trim();

    if (!name || creating.value) {
        return;
    }

    creating.value = true;
    error.value = '';

    try {
        const res = await apiFetch('/api/admin/teams', {
            method: 'POST',
            body: JSON.stringify({
                name,
                description: newDescription.value.trim(),
            }),
        });

        if (!res.ok) {
            const data = await res.json();
            error.value =
                data.errors?.name?.[0] ??
                data.message ??
                t.value.teamManagerLoadError;

            return;
        }

        const created: TeamEntry = await res.json();
        teams.value = [...teams.value, created].sort((a, b) =>
            a.name.localeCompare(b.name),
        );
        newName.value = '';
        newDescription.value = '';
    } catch (err) {
        console.error('Failed to create team:', err);
        error.value = t.value.teamManagerLoadError;
    } finally {
        creating.value = false;
    }
}

function startEdit(team: TeamEntry) {
    editingId.value = team.id;
    editName.value = team.name;
    editDescription.value = team.description;
}

function cancelEdit() {
    editingId.value = null;
}

async function saveEdit(team: TeamEntry) {
    const name = editName.value.trim();

    if (!name || savingEdit.value) {
        return;
    }

    savingEdit.value = true;

    try {
        const res = await apiFetch(`/api/admin/teams/${team.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                name,
                description: editDescription.value.trim(),
            }),
        });

        if (!res.ok) {
            return;
        }

        const updated = await res.json();
        team.name = updated.name;
        team.description = updated.description;
        editingId.value = null;
    } catch (err) {
        console.error('Failed to update team:', err);
    } finally {
        savingEdit.value = false;
    }
}

function armDelete(teamId: number) {
    confirmingDeleteId.value =
        confirmingDeleteId.value === teamId ? null : teamId;
}

async function deleteTeam(teamId: number) {
    try {
        const res = await apiFetch(`/api/admin/teams/${teamId}`, {
            method: 'DELETE',
        });

        if (!res.ok) {
            return;
        }

        teams.value = teams.value.filter((team) => team.id !== teamId);
        confirmingDeleteId.value = null;
    } catch (err) {
        console.error('Failed to delete team:', err);
    }
}

async function addMember(team: TeamEntry) {
    const identifier = (memberIdentifier.value[team.id] ?? '').trim();

    if (!identifier || addingMember.value === team.id) {
        return;
    }

    addingMember.value = team.id;
    memberError.value = { ...memberError.value, [team.id]: '' };

    try {
        const res = await apiFetch(`/api/admin/teams/${team.id}/members`, {
            method: 'POST',
            body: JSON.stringify({ identifier }),
        });
        const data = await res.json();

        if (!res.ok) {
            memberError.value = {
                ...memberError.value,
                [team.id]:
                    data.errors?.identifier?.[0] ??
                    data.message ??
                    t.value.teamManagerLoadError,
            };

            return;
        }

        team.users = [...team.users, data];
        team.users_count += 1;
        memberIdentifier.value = { ...memberIdentifier.value, [team.id]: '' };
    } catch (err) {
        console.error('Failed to add team member:', err);
    } finally {
        addingMember.value = null;
    }
}

async function removeMember(team: TeamEntry, member: TeamMember) {
    try {
        const res = await apiFetch(
            `/api/admin/teams/${team.id}/members/${member.id}`,
            { method: 'DELETE' },
        );

        if (!res.ok) {
            return;
        }

        team.users = team.users.filter((u) => u.id !== member.id);
        team.users_count -= 1;
    } catch (err) {
        console.error('Failed to remove team member:', err);
    }
}

onMounted(load);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        @click.self="emit('close')"
    >
        <div
            class="flex h-[82vh] w-[92vw] max-w-xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
        >
            <div
                class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-4"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <Users class="h-5 w-5" />
                    </div>
                    <h3 class="text-base font-bold">
                        {{ t.teamManagerTitle }}
                    </h3>
                </div>
                <button
                    @click="emit('close')"
                    :aria-label="t.close"
                    class="rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- Новая команда -->
            <div class="border-b border-slate-800 p-4">
                <div class="flex items-end gap-2 text-xs">
                    <div class="flex-1">
                        <label
                            class="mb-1 block font-semibold text-slate-300"
                            >{{ t.teamNameLabel }}</label
                        >
                        <input
                            v-model="newName"
                            type="text"
                            :placeholder="t.teamNamePlaceholder"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            @keydown.enter="createTeam"
                        />
                    </div>
                    <div class="flex-1">
                        <label
                            class="mb-1 block font-semibold text-slate-300"
                            >{{ t.descriptionLabel }}</label
                        >
                        <input
                            v-model="newDescription"
                            type="text"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                            @keydown.enter="createTeam"
                        />
                    </div>
                    <button
                        :disabled="!newName.trim() || creating"
                        @click="createTeam"
                        class="flex shrink-0 items-center gap-1.5 rounded-xl bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Loader2
                            v-if="creating"
                            class="h-3.5 w-3.5 animate-spin"
                        />
                        <Plus v-else class="h-3.5 w-3.5" />
                        <span>{{ t.teamManagerCreateAction }}</span>
                    </button>
                </div>
                <p
                    v-if="error"
                    class="mt-2 rounded-xl border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-[11px] text-rose-300"
                >
                    {{ error }}
                </p>
            </div>

            <!-- Список команд -->
            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
                <div
                    v-if="loading"
                    class="flex justify-center py-8 text-slate-400"
                >
                    <Loader2 class="h-5 w-5 animate-spin" />
                </div>
                <p
                    v-else-if="!teams.length"
                    class="py-8 text-center text-xs text-slate-500"
                >
                    {{ t.teamManagerEmpty }}
                </p>

                <div
                    v-for="team in teams"
                    :key="team.id"
                    class="overflow-hidden rounded-2xl border border-slate-700/60 bg-slate-800/40"
                >
                    <!-- Строка команды -->
                    <div v-if="editingId === team.id" class="space-y-2 p-3">
                        <input
                            v-model="editName"
                            type="text"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                        <input
                            v-model="editDescription"
                            type="text"
                            :placeholder="t.descriptionLabel"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                        <div class="flex justify-end gap-2">
                            <button
                                @click="cancelEdit"
                                :disabled="savingEdit"
                                class="rounded-xl px-3 py-1.5 text-[11px] font-semibold text-slate-400 hover:text-slate-200"
                            >
                                {{ t.cancelAction }}
                            </button>
                            <button
                                @click="saveEdit(team)"
                                :disabled="!editName.trim() || savingEdit"
                                class="rounded-xl bg-blue-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-blue-500 disabled:opacity-50"
                            >
                                {{ savingEdit ? t.savingAction : t.saveAction }}
                            </button>
                        </div>
                    </div>

                    <div v-else class="flex items-center gap-2 p-3">
                        <button
                            type="button"
                            @click="toggleExpand(team.id)"
                            class="flex min-w-0 flex-1 items-center gap-2 text-start"
                        >
                            <component
                                :is="
                                    expandedId === team.id
                                        ? ChevronDown
                                        : ChevronRight
                                "
                                class="h-3.5 w-3.5 shrink-0 text-slate-500"
                            />
                            <span
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-sky-500 to-sky-700 text-[10.5px] font-bold text-white"
                            >
                                {{ team.name.slice(0, 1).toUpperCase() }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-xs font-bold"
                                    >{{ team.name }}</span
                                >
                                <span
                                    class="block truncate text-[10.5px] text-slate-500"
                                >
                                    {{
                                        team.description ||
                                        t.teamManagerMembersLabel
                                    }}
                                    · {{ team.users_count }}
                                </span>
                            </span>
                        </button>
                        <button
                            @click="startEdit(team)"
                            :aria-label="t.editAction"
                            :title="t.editAction"
                            class="shrink-0 rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-700/60 hover:text-slate-200"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </button>
                        <button
                            @click="
                                confirmingDeleteId === team.id
                                    ? deleteTeam(team.id)
                                    : armDelete(team.id)
                            "
                            @blur="
                                confirmingDeleteId === team.id &&
                                (confirmingDeleteId = null)
                            "
                            :aria-label="t.deleteAction"
                            :title="
                                confirmingDeleteId === team.id
                                    ? t.confirmDelete
                                    : t.deleteAction
                            "
                            :class="[
                                'shrink-0 rounded-lg p-1.5 transition-colors',
                                confirmingDeleteId === team.id
                                    ? 'bg-rose-600 text-white hover:bg-rose-500'
                                    : 'text-slate-500 hover:bg-rose-950/40 hover:text-rose-400',
                            ]"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <!-- Участники -->
                    <div
                        v-if="expandedId === team.id"
                        class="space-y-2 border-t border-slate-700/60 bg-slate-900/40 p-3"
                    >
                        <p
                            v-if="!team.users.length"
                            class="py-1 text-center text-[11px] text-slate-500"
                        >
                            {{ t.teamManagerNoMembers }}
                        </p>
                        <div
                            v-for="member in team.users"
                            :key="member.id"
                            class="flex items-center justify-between gap-2 rounded-xl border border-slate-800 bg-slate-800/40 p-2 text-xs"
                        >
                            <div class="min-w-0">
                                <div
                                    class="truncate font-semibold text-slate-200"
                                >
                                    {{ member.name }}
                                </div>
                                <div
                                    class="truncate text-[10px] text-slate-500"
                                >
                                    {{ member.email }}
                                </div>
                            </div>
                            <button
                                @click="removeMember(team, member)"
                                :aria-label="t.teamManagerRemoveMemberAction"
                                :title="t.teamManagerRemoveMemberAction"
                                class="shrink-0 rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
                            >
                                <UserMinus class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <input
                                v-model="memberIdentifier[team.id]"
                                type="text"
                                :placeholder="t.shareIdentifierPlaceholder"
                                class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                                @keydown.enter="addMember(team)"
                            />
                            <button
                                :disabled="
                                    !memberIdentifier[team.id]?.trim() ||
                                    addingMember === team.id
                                "
                                @click="addMember(team)"
                                class="flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-1.5 text-[11px] font-semibold text-slate-200 transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Loader2
                                    v-if="addingMember === team.id"
                                    class="h-3.5 w-3.5 animate-spin"
                                />
                                <UserPlus v-else class="h-3.5 w-3.5" />
                                <span>{{ t.teamManagerAddMemberAction }}</span>
                            </button>
                        </div>
                        <p
                            v-if="memberError[team.id]"
                            class="rounded-xl border border-rose-800/50 bg-rose-950/30 px-3 py-1.5 text-[11px] text-rose-300"
                        >
                            {{ memberError[team.id] }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
