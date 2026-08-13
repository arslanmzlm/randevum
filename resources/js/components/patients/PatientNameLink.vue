<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { show as patientShow } from '@/routes/patients';

// A soft-deleted patient has no reachable detail page (the route 404s), so the
// name renders as plain text there instead of a dead link.
const props = withDefaults(
    defineProps<{
        patient: { id: number; full_name: string; is_deleted: boolean };
        /** Subdued link colouring for secondary columns. */
        subtle?: boolean;
    }>(),
    { subtle: false },
);

defineOptions({ inheritAttrs: false });

const { t } = useI18n();

const linkClass = computed(() =>
    props.subtle
        ? 'text-surface-700 transition-colors hover:text-primary-600'
        : 'text-primary-600 transition-colors hover:text-primary-700 hover:underline',
);
</script>

<template>
    <div class="flex items-center gap-2">
        <Link
            v-if="!patient.is_deleted"
            :href="patientShow(patient.id).url"
            :class="linkClass"
            v-bind="$attrs"
        >
            {{ patient.full_name }}
        </Link>
        <span v-else class="text-surface-700" v-bind="$attrs">
            {{ patient.full_name }}
        </span>

        <Tag
            v-if="patient.is_deleted"
            severity="danger"
            :value="t('patient.deleted_badge')"
        />
    </div>
</template>
