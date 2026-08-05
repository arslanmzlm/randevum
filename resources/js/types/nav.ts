import type { Component } from 'vue';

// A single sidebar navigation entry. `icon` is a Tabler Vue component.
export interface NavItem {
    label: string;
    href: string;
    icon: Component;
    /**
     * Inertia page component(s) this entry stands for (`usePage().component`), e.g.
     * 'appointments/Index' or ['patients/Index', 'patients/Show']. Highlighting reads this
     * instead of the URL, so filters, pagination and tab params never break the match.
     */
    component?: string | string[];
    /**
     * Query params that must be present for the entry to win, for two entries sharing one page
     * component (the appointment list vs its no-show view). Matched as a subset, so extra params
     * (page, sort, a second filter) do not spoil it.
     */
    match?: Record<string, string>;
}

// A collapsible sidebar section. Everything that is not part of the daily loop lives in one of
// these so the top level stays short; a group opens automatically when it holds the active route.
export interface NavGroup {
    key: string;
    label: string;
    icon: Component;
    items: NavItem[];
}
