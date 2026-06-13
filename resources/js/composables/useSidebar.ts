import { useMediaQuery, useStorage } from '@vueuse/core';
import { ref, watch } from 'vue';

// Per-page desktop sidebar intent, declared via Inertia v3 `setLayoutProps`.
// 'default' → the user's stored collapsed preference; 'collapsed' → forced rail;
// 'hidden' → no desktop sidebar (the toggle still opens the off-canvas drawer).
export type SidebarMode = 'default' | 'collapsed' | 'hidden';

// Module-level singletons so the toggle button, the desktop aside, and the
// drawer all share one source of truth (mirrors useContentWidth's pattern).

// Desktop rail state — persisted per-browser.
const collapsed = useStorage('sidebar-collapsed', false);

// Off-canvas drawer open flag — ephemeral (mobile, or a `hidden` page on desktop).
const mobileOpen = ref(false);

// Tailwind `lg` breakpoint: at/above this the desktop sidebar replaces the drawer.
const isDesktop = useMediaQuery('(min-width: 1024px)');

// Set by AppLayout from the active page's `sidebar` mode: when the desktop sidebar
// is hidden, even a desktop toggle opens the drawer instead of flipping the rail.
const desktopHidden = ref(false);

// Crossing into desktop must close a drawer left open on mobile.
watch(isDesktop, (desktop) => {
    if (desktop) {
        mobileOpen.value = false;
    }
});

export function useSidebar() {
    function toggle(): void {
        if (isDesktop.value && !desktopHidden.value) {
            collapsed.value = !collapsed.value;
        } else {
            mobileOpen.value = !mobileOpen.value;
        }
    }

    function closeMobile(): void {
        mobileOpen.value = false;
    }

    function expand(): void {
        collapsed.value = false;
    }

    function setDesktopHidden(value: boolean): void {
        desktopHidden.value = value;
    }

    return {
        collapsed,
        mobileOpen,
        isDesktop,
        desktopHidden,
        toggle,
        closeMobile,
        expand,
        setDesktopHidden,
    };
}
