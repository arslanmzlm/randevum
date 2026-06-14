import { useMediaQuery, useStorage } from '@vueuse/core';
import { ref, watch } from 'vue';

// Right-hand "hızlı erişim" sidebar. Module-level singletons so the header toggle,
// the desktop aside, and the drawer share one source of truth (mirrors useSidebar).

// Desktop visibility — persisted per-browser, open by default for discoverability.
const desktopOpen = useStorage('quick-access-open', true);

// Off-canvas drawer flag — ephemeral (narrow screens).
const mobileOpen = ref(false);

// Two fixed sidebars only fit from `xl` up; below that the panel becomes a drawer.
const isWide = useMediaQuery('(min-width: 1280px)');

// Crossing into wide must close a drawer left open on a narrow screen.
watch(isWide, (wide) => {
    if (wide) {
        mobileOpen.value = false;
    }
});

export function useQuickAccess() {
    function toggle(): void {
        if (isWide.value) {
            desktopOpen.value = !desktopOpen.value;
        } else {
            mobileOpen.value = !mobileOpen.value;
        }
    }

    function closeMobile(): void {
        mobileOpen.value = false;
    }

    return { desktopOpen, mobileOpen, isWide, toggle, closeMobile };
}
