<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconChevronRight, IconHome } from '@tabler/icons-vue';
import { dashboard } from '@/routes';

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

defineProps<{
    title: string;
    description?: string;
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <nav
            v-if="breadcrumbs?.length"
            class="flex items-center gap-1.5 text-sm text-surface-400"
            :aria-label="title"
        >
            <Link
                :href="dashboard().url"
                class="flex items-center text-surface-400 transition-colors hover:text-surface-700"
            >
                <IconHome class="size-4" />
            </Link>
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <IconChevronRight class="size-3.5 text-surface-300" />
                <Link
                    v-if="item.href"
                    :href="item.href"
                    class="transition-colors hover:text-surface-700"
                >
                    {{ item.label }}
                </Link>
                <span v-else class="text-surface-600">{{ item.label }}</span>
            </template>
        </nav>

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <h1 class="text-2xl font-semibold text-surface-900">
                    {{ title }}
                </h1>
                <p v-if="description" class="text-sm text-surface-500">
                    {{ description }}
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
