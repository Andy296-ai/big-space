<?php

namespace App\Concerns;

use Illuminate\Support\Collection;

/**
 * Общий парсинг [[user:ID]] — используется и в сообщениях мессенджера, и в
 * комментариях к узлу. Без персистентной таблицы-связки (в отличие от
 * message_node_references у [[node:ID]]): для упоминаний людей нет разницы
 * в доступе между читателями одного и того же разговора/узла, поэтому
 * список каждый раз пересчитывается заново по актуальному составу, а не
 * хранится.
 */
trait ResolvesUserMentions
{
    private const USER_MENTION_PATTERN = '/\[\[user:(\d+)\]\]/';

    /**
     * @param  Collection<int, string>  $eligibleNames  id => name, только те, на кого разрешено упоминание в этом контексте
     * @return array<int, array<string, mixed>>
     */
    private function resolveUserMentions(?string $body, Collection $eligibleNames): array
    {
        if (! $body) {
            return [];
        }

        preg_match_all(self::USER_MENTION_PATTERN, $body, $matches);
        $ids = array_unique(array_map('intval', $matches[1]));

        return collect($ids)
            ->filter(fn (int $id) => $eligibleNames->has($id))
            ->map(fn (int $id) => ['id' => $id, 'name' => $eligibleNames->get($id)])
            ->values()
            ->all();
    }
}
