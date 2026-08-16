import { computed, ref } from 'vue';
import type { Ref } from 'vue';

export interface MentionCandidate {
    id: number;
    name: string;
}

/**
 * Общий разбор @-триггера для композеров сообщений/комментариев — синтаксис
 * упоминания [[user:ID]] на сервере уже есть (см. ResolvesUserMentions),
 * здесь только клиентская сторона: детект "@query" перед курсором, список
 * подходящих кандидатов, вставка токена на его место.
 */
export function useMentionAutocomplete(candidates: Ref<MentionCandidate[]>) {
    const query = ref<string | null>(null);
    const triggerIndex = ref<number | null>(null);

    const matches = computed(() => {
        if (query.value === null) {
            return [];
        }

        const q = query.value.toLowerCase();

        return candidates.value
            .filter((c) => c.name.toLowerCase().includes(q))
            .slice(0, 6);
    });

    /** Вызывать на каждый ввод в текстовое поле — text и позиция курсора. */
    function handleInput(text: string, cursor: number) {
        const upToCursor = text.slice(0, cursor);
        const match = upToCursor.match(/(?:^|\s)@([^\s@]*)$/);

        if (match) {
            query.value = match[1];
            triggerIndex.value = cursor - match[1].length - 1;
        } else {
            close();
        }
    }

    /**
     * Заменяет "@query" на "[[user:ID]] " и возвращает новый текст + позицию
     * курсора после вставки. Конец query берётся из triggerIndex + длины
     * query.value (то, что реально накопил handleInput), а НЕ из живой
     * позиции курсора на момент клика — стрелками/кликом внутри открытого
     * "@bo" можно сдвинуть курсор, не породив новый input-событие (query.value
     * при этом не обновится), и тогда срез "после" по устаревшему курсору
     * оставлял бы хвост незаменённого query рядом со вставленным токеном.
     */
    function applyMention(
        text: string,
        candidate: MentionCandidate,
    ): { text: string; cursor: number } {
        if (triggerIndex.value === null || query.value === null) {
            return { text, cursor: text.length };
        }

        const queryEnd = triggerIndex.value + 1 + query.value.length;
        const before = text.slice(0, triggerIndex.value);
        const after = text.slice(queryEnd);
        const insertion = `[[user:${candidate.id}]] `;
        const newText = before + insertion + after;

        close();

        return { text: newText, cursor: before.length + insertion.length };
    }

    function close() {
        query.value = null;
        triggerIndex.value = null;
    }

    return { query, matches, handleInput, applyMention, close };
}
