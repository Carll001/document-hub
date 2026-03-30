import { nextTick, onBeforeUnmount, ref, watch, type Ref } from 'vue';

export function useRenderedEmailFrame(selectedEmailHtmlUrl: Ref<string | null>) {
    const renderedEmailFrame = ref<HTMLIFrameElement | null>(null);
    let renderedEmailResizeObserver: ResizeObserver | null = null;

    function cleanupRenderedEmailObserver(): void {
        renderedEmailResizeObserver?.disconnect();
        renderedEmailResizeObserver = null;
    }

    function resizeRenderedEmailFrame(): void {
        const frame = renderedEmailFrame.value;
        const document = frame?.contentDocument;
        const body = document?.body;
        const root = document?.documentElement;

        if (!frame || !body || !root) {
            return;
        }

        const height = Math.max(
            body.scrollHeight,
            body.offsetHeight,
            root.scrollHeight,
            root.offsetHeight,
            320,
        );

        frame.style.height = `${height}px`;
    }

    function bindRenderedEmailFrameObserver(): void {
        cleanupRenderedEmailObserver();
        resizeRenderedEmailFrame();

        if (typeof window === 'undefined' || !('ResizeObserver' in window)) {
            return;
        }

        const frame = renderedEmailFrame.value;
        const document = frame?.contentDocument;
        const body = document?.body;
        const root = document?.documentElement;

        if (!body || !root) {
            return;
        }

        renderedEmailResizeObserver = new window.ResizeObserver(() => {
            resizeRenderedEmailFrame();
        });

        renderedEmailResizeObserver.observe(body);
        renderedEmailResizeObserver.observe(root);
    }

    async function onRenderedEmailLoad(): Promise<void> {
        await nextTick();
        bindRenderedEmailFrameObserver();

        if (typeof window === 'undefined') {
            return;
        }

        for (const delay of [150, 600, 1500]) {
            window.setTimeout(() => {
                resizeRenderedEmailFrame();
            }, delay);
        }
    }

    watch(selectedEmailHtmlUrl, async () => {
        cleanupRenderedEmailObserver();

        await nextTick();

        if (renderedEmailFrame.value) {
            renderedEmailFrame.value.style.height = '320px';
        }
    });

    onBeforeUnmount(() => {
        cleanupRenderedEmailObserver();
    });

    return {
        onRenderedEmailLoad,
        renderedEmailFrame,
    };
}
