<script setup lang="ts">
import SectionCard from '@/components/SectionCard.vue';

// The single top card shared by the create and bulk-create appointment forms: one SectionCard
// holding side-by-side sections split by dividers (stacked + horizontally divided on mobile).
// Extracted so the two pages can't drift on the card/grid/divider structure — only the column
// COUNT and each column's content differ. Each slotted section owns its own column padding/header.
withDefaults(defineProps<{ columns?: 2 | 3 }>(), { columns: 3 });
</script>

<template>
    <SectionCard>
        <div
            class="grid grid-cols-1 divide-y divide-surface-200 lg:divide-x lg:divide-y-0"
            :class="columns === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2'"
        >
            <slot />
        </div>
    </SectionCard>
</template>
