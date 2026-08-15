import { ref, shallowRef } from 'vue';

export type MediaRecorderKind = 'audio' | 'video';

export interface RecordResult {
    blob: Blob;
    durationMs: number;
    mimeType: string;
}

/**
 * Обёртка над MediaRecorder — запись голосовых/видео-сообщений (запись и
 * отправка, без звонков). audio/webm;codecs=opus и video/webm;codecs=vp9,opus
 * предпочтительны, mp4-варианты — резерв для Safari, у которого их нет.
 */
export function useMediaRecorder(kind: MediaRecorderKind) {
    const isRecording = ref(false);
    const elapsedMs = ref(0);
    const error = ref('');
    const stream = shallowRef<MediaStream | null>(null);

    let recorder: MediaRecorder | null = null;
    let chunks: BlobPart[] = [];
    let startedAt = 0;
    let timer: ReturnType<typeof setInterval> | null = null;

    function pickMimeType(): string {
        const candidates =
            kind === 'audio'
                ? ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
                : ['video/webm;codecs=vp9,opus', 'video/webm', 'video/mp4'];

        return (
            candidates.find((type) => MediaRecorder.isTypeSupported(type)) ?? ''
        );
    }

    function stopTracks() {
        stream.value?.getTracks().forEach((track) => track.stop());
        stream.value = null;
    }

    function clearTimer() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    async function start(): Promise<void> {
        error.value = '';

        try {
            stream.value = await navigator.mediaDevices.getUserMedia(
                kind === 'audio'
                    ? { audio: true }
                    : { audio: true, video: true },
            );
        } catch {
            error.value = 'permission_denied';

            return;
        }

        const mimeType = pickMimeType();
        recorder = mimeType
            ? new MediaRecorder(stream.value, { mimeType })
            : new MediaRecorder(stream.value);
        chunks = [];

        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };

        startedAt = Date.now();
        elapsedMs.value = 0;
        isRecording.value = true;
        recorder.start();

        timer = setInterval(() => {
            elapsedMs.value = Date.now() - startedAt;
        }, 200);
    }

    function stop(): Promise<RecordResult | null> {
        return new Promise((resolve) => {
            if (!recorder || !isRecording.value) {
                resolve(null);

                return;
            }

            const mimeType = recorder.mimeType;

            recorder.onstop = () => {
                clearTimer();
                stopTracks();
                isRecording.value = false;

                if (!chunks.length) {
                    resolve(null);

                    return;
                }

                resolve({
                    blob: new Blob(chunks, { type: mimeType }),
                    durationMs: Date.now() - startedAt,
                    mimeType,
                });
            };

            recorder.stop();
        });
    }

    /** Обрывает запись без отправки — отдельно от stop(), результат не нужен вызывающему. */
    function discard() {
        if (recorder && isRecording.value) {
            recorder.onstop = null;
            recorder.stop();
        }

        clearTimer();
        stopTracks();
        isRecording.value = false;
        chunks = [];
    }

    return { isRecording, elapsedMs, error, stream, start, stop, discard };
}
