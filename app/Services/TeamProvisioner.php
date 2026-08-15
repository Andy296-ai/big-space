<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Создание команд и синхронизация их состава с групповым разговором в
 * мессенджере — тот же принцип, что и у SpaceProvisioner (создание сущности
 * каскадом тянет за собой связанную настройку), явным кодом, а не через
 * модельные события — так же, как SpaceCollaboratorController сам ведёт
 * свой pivot.
 */
class TeamProvisioner
{
    /** @param  array<int, int>  $memberUserIds */
    public function createTeam(string $name, string $description, array $memberUserIds): Team
    {
        return DB::transaction(function () use ($name, $description, $memberUserIds) {
            $team = Team::create(['name' => $name, 'description' => $description]);
            $conversation = Conversation::create(['type' => Conversation::TYPE_TEAM, 'team_id' => $team->id]);

            if ($memberUserIds !== []) {
                $team->users()->attach($memberUserIds);
                $conversation->participants()->attach($memberUserIds);
            }

            return $team;
        });
    }

    public function addMember(Team $team, User $user): void
    {
        if ($team->users()->where('user_id', $user->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($team, $user) {
            $team->users()->attach($user->id);
            $team->conversation?->participants()->attach($user->id);
        });
    }

    public function removeMember(Team $team, User $user): void
    {
        DB::transaction(function () use ($team, $user) {
            $team->users()->detach($user->id);
            $team->conversation?->participants()->detach($user->id);
        });
    }

    public function deleteTeam(Team $team): void
    {
        $conversation = $team->conversation;

        if ($conversation !== null) {
            // Каскад по FK не поднимет модельные события удаления — то же
            // самое уже отмечено в Admin\UserController про query-builder
            // delete(). MessageAttachment чистит файл на диске именно в
            // своём deleting-хуке, поэтому проходим по вложениям через
            // Eloquent явно, до того как каскад унесёт messages/conversation.
            MessageAttachment::whereIn('message_id', $conversation->messages()->pluck('id'))
                ->get()
                ->each->delete();
        }

        $team->delete();
    }
}
