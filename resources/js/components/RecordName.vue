<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

// The one place that decides how a record's name reads once the record is soft-deleted:
// the badge text, its colour and the gap to the name. Every surface naming a patient or a
// doctor renders through here so those three never drift apart.
const props = withDefaults(
    defineProps<{
        name: string;
        deleted?: boolean;
        /** Detail-page URL; a deleted record links nowhere, so it falls back to plain text. */
        href?: string | null;
        /** Class for the anchor variant. */
        linkClass?: string;
        /** Class for the plain-text variant. */
        textClass?: string;
    }>(),
    {
        deleted: false,
        href: null,
        linkClass: undefined,
        textClass: undefined,
    },
);

defineOptions({ inheritAttrs: false });

const { t } = useI18n();

const linked = computed(() => props.href !== null && !props.deleted);
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <Link v-if="linked" :href="href!" :class="linkClass" v-bind="$attrs">
            {{ name }}
        </Link>
        <span v-else :class="textClass" v-bind="$attrs">
            {{ name }}
        </span>

        <Tag
            v-if="deleted"
            severity="danger"
            class="shrink-0"
            :value="t('common.deleted_badge')"
        />
    </div>
</template>
