import type { Component } from 'vue';

// A single sidebar navigation entry. `icon` is a Tabler Vue component.
export interface NavItem {
    label: string;
    href: string;
    icon: Component;
}
