<script setup lang="ts">
import type { Component } from 'vue';

// Pure presentational KPI tile: tinted icon badge + big value + label (+ optional sublabel).
// Prop-driven so StatCardsRow owns the data/permission gating. The card surface uses surface-*
// tokens (dark-ready); the accent badge uses a fixed palette tint, matching the existing badge
// convention (AvailabilityBadge, treatments/Process) — dark-mode variants come in the deferred sweep.
type Accent = 'primary' | 'emerald' | 'amber' | 'sky';

withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        icon: Component;
        sublabel?: string;
        accent?: Accent;
    }>(),
    { accent: 'primary' },
);

const ACCENT_CLASS: Record<Accent, string> = {
    primary: 'bg-primary-50 text-primary-600',
    emerald: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    sky: 'bg-sky-50 text-sky-600',
};
</script>

<template>
    <div
        class="flex items-center gap-4 rounded-2xl border border-surface-200 bg-surface-0 p-5"
    >
        <span
            class="flex size-12 shrink-0 items-center justify-center rounded-xl"
            :class="ACCENT_CLASS[accent]"
            aria-hidden="true"
        >
            <component :is="icon" class="size-6" />
        </span>

        <div class="flex min-w-0 flex-col gap-0.5">
            <span class="truncate text-sm text-surface-500">{{ label }}</span>
            <span class="text-2xl font-semibold text-surface-900">
                {{ value }}
            </span>
            <span v-if="sublabel" class="text-xs text-surface-400">
                {{ sublabel }}
            </span>
        </div>
    </div>
</template>
