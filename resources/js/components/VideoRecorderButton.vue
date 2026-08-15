<script setup lang="ts">
import { Trash2, Video as VideoIcon, Square } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { useT } from '../lib/i18n';
import { useMediaRecorder } from '../lib/useMediaRecorder';
import type { RecordResult } from '../lib/useMediaRecorder';

const emit = defineEmits<{
    (e: 'recorded', payload: RecordResult): void;
    (e: 'error'): void;
}>();

const t = useT();
const { isRecording, elapsedMs, error, stream, start, stop, discard } =
    useMediaRecorder('video');

const previewEl = ref<HTMLVideoElement | null>(null);

// Живой self-view во время записи — без него не видно, что попадает в кадр.
watch(stream, (value) => {
    if (previewEl.value) {
        previewEl.value.srcObject = value;
    }
});

function formatElapsed(ms: number): string {
    const totalSeconds = Math.floor(ms / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

async function toggle() {
    if (isRecording.value) {
        return;
    }

    await start();

    if (error.value) {
        emit('error');
    }
}

async function finish() {
    const result = await stop();

    if (result) {
        emit('recorded', result);
    }
}

function cancel() {
    discard();
}
</script>

<template>
    <div
        v-if="isRecording"
        class="flex shrink-0 items-center gap-2 rounded-xl border border-rose-800/60 bg-rose-950/30 p-1.5"
    >
        <video
            ref="previewEl"
            autoplay
            muted
            playsinline
            class="h-9 w-12 shrink-0 rounded-lg bg-black object-cover"
        />
        <span
            class="w-9 shrink-0 font-mono text-[11px] text-rose-300 tabular-nums"
        >
            {{ formatElapsed(elapsedMs) }}
        </span>
        <button
            type="button"
            @click="cancel"
            :aria-label="t.messengerDiscardRecording"
            :title="t.messengerDiscardRecording"
            class="shrink-0 rounded-lg p-1 text-slate-400 transition-colors hover:text-rose-400"
        >
            <Trash2 class="h-3.5 w-3.5" />
        </button>
        <button
            type="button"
            @click="finish"
            :aria-label="t.messengerSendAction"
            :title="t.messengerSendAction"
            class="flex shrink-0 items-center justify-center rounded-lg bg-rose-600 p-1.5 text-white transition-colors hover:bg-rose-500"
        >
            <Square class="h-3 w-3" />
        </button>
    </div>
    <button
        v-else
        type="button"
        @click="toggle"
        :aria-label="t.messengerRecordVideoAction"
        :title="t.messengerRecordVideoAction"
        class="flex shrink-0 items-center justify-center rounded-xl border border-slate-700 bg-slate-800/60 p-2.5 text-slate-300 transition-colors hover:bg-slate-700"
    >
        <VideoIcon class="h-4 w-4" />
    </button>
</template>
