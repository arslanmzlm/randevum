<script setup lang="ts">
import { computed } from 'vue';
import RecordName from '@/components/RecordName.vue';
import { show as patientShow } from '@/routes/patients';

// A soft-deleted patient has no reachable detail page (the route 404s), so RecordName
// downgrades the name to plain text there instead of rendering a dead link.
//
// Two call shapes, because the payloads differ: resources that nest a patient object
// (case/treatment) pass `patient`, while the appointment/payment-plan payloads carry
// patient_id / patient_name / patient_is_deleted as flat siblings and pass id/name/deleted.
const props = withDefaults(
    defineProps<{
        patient?: { id: number; full_name: string; is_deleted: boolean };
        id?: number;
        name?: string;
        deleted?: boolean;
        /** Subdued link colouring for secondary columns. */
        subtle?: boolean;
        /** The surrounding row is already a link — render the name as text, never a nested anchor. */
        plain?: boolean;
    }>(),
    {
        patient: undefined,
        id: undefined,
        name: undefined,
        deleted: false,
        subtle: false,
        plain: false,
    },
);

defineOptions({ inheritAttrs: false });

const fullName = computed(() => props.patient?.full_name ?? props.name ?? '');

const isDeleted = computed(() => props.patient?.is_deleted ?? props.deleted);

const href = computed(() => {
    const patientId = props.patient?.id ?? props.id;

    return !props.plain && patientId !== undefined
        ? patientShow(patientId).url
        : null;
});

const linkClass = computed(() =>
    props.subtle
        ? 'text-surface-700 transition-colors hover:text-primary-600'
        : 'text-primary-600 transition-colors hover:text-primary-700 hover:underline',
);
</script>

<template>
    <RecordName
        :name="fullName"
        :deleted="isDeleted"
        :href="href"
        :link-class="linkClass"
        :text-class="plain ? undefined : 'text-surface-700'"
        v-bind="$attrs"
    />
</template>
