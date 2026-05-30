import { ref, watch } from 'vue';

// Module-level ref so the topbar toggle and the layout wrapper share one source.
export type ContentWidth = 'container' | 'fluid';

const STORAGE_KEY = 'content-width';

function readStored(): ContentWidth {
    if (typeof window === 'undefined') {
        return 'container';
    }

    return window.localStorage.getItem(STORAGE_KEY) === 'fluid'
        ? 'fluid'
        : 'container';
}

const width = ref<ContentWidth>(readStored());

watch(width, (value) => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, value);
    }
});

export function useContentWidth() {
    function toggle(): void {
        width.value = width.value === 'fluid' ? 'container' : 'fluid';
    }

    return { width, toggle };
}
