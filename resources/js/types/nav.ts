import type { Component } from 'vue';

// A single sidebar navigation entry. `icon` is a Tabler Vue component.
export interface NavItem {
    label: string;
    href: string;
    icon: Component;
}

// A collapsible sidebar section. Everything that is not part of the daily loop lives in one of
// these so the top level stays short; a group opens automatically when it holds the active route.
export interface NavGroup {
    key: string;
    label: string;
    icon: Component;
    items: NavItem[];
}
