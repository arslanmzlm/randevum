<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconCalendarEvent,
    IconMail,
    IconNotes,
    IconPencil,
    IconPhone,
    IconUser,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { update as updateNotes } from '@/routes/patients/notes';
import type { Patient, PatientNotesFormData } from '@/types/patient';

const props = defineProps<{ patient: Patient }>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateOnly } = useDateTime();

const canEditNotes = computed(() => can('patients.note.update'));

const birthDateLabel = computed(() => {
    if (!props.patient.birth_date) {
        return t('patient.not_specified');
    }

    const formatted = formatDateOnly(props.patient.birth_date);

    return props.patient.age !== null
        ? `${formatted} · ${t('patient.age_value', { age: props.patient.age })}`
        : formatted;
});

const genderLabel = computed(() =>
    props.patient.gender
        ? t(`patient.gender.${props.patient.gender}`)
        : t('patient.not_specified'),
);

const contactRows = computed(() => [
    {
        icon: IconPhone,
        label: t('patient.fields.phone'),
        value: props.patient.phone,
    },
    {
        icon: IconPhone,
        label: t('patient.fields.contact_phone'),
        value: props.patient.contact_phone,
    },
    {
        icon: IconMail,
        label: t('patient.fields.email'),
        value: props.patient.email,
    },
    {
        icon: IconCalendarEvent,
        label: t('patient.fields.birth_date'),
        value: birthDateLabel.value,
    },
    {
        icon: IconUser,
        label: t('patient.fields.gender'),
        value: genderLabel.value,
    },
]);

const editingNotes = ref(false);

const notesForm = useForm<PatientNotesFormData>({
    notes: props.patient.notes ?? '',
});

function startEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.patient.notes ?? '';
    editingNotes.value = true;
}

function cancelEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.patient.notes ?? '';
    editingNotes.value = false;
}

function saveNotes(): void {
    notesForm.patch(updateNotes(props.patient.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingNotes.value = false;
        },
    });
}
</script>

<template>
    <SectionCard :icon="IconUser" :title="t('patient.sections.profile')">
        <template v-if="patient.is_legacy" #actions>
            <Tag severity="warn" :value="t('patient.legacy_badge')" />
        </template>

        <div class="flex flex-col gap-5">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <div
                    v-for="(row, idx) in contactRows"
                    :key="idx"
                    class="flex items-start gap-3"
                >
                    <component
                        :is="row.icon"
                        class="mt-0.5 size-4 shrink-0 text-surface-400"
                    />
                    <div class="flex min-w-0 flex-col">
                        <dt class="text-xs text-surface-500">
                            {{ row.label }}
                        </dt>
                        <dd class="text-sm text-surface-900">
                            {{ row.value || t('patient.not_specified') }}
                        </dd>
                    </div>
                </div>
            </dl>

            <div
                class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
            >
                <span class="text-sm font-medium text-surface-900">
                    {{ t('patient.fields.notification_enabled') }}
                </span>
                <Tag
                    :severity="
                        patient.notification_enabled ? 'success' : 'secondary'
                    "
                    :value="
                        patient.notification_enabled
                            ? t('patient.notifications_on')
                            : t('patient.notifications_off')
                    "
                />
            </div>

            <div class="flex flex-col gap-2">
                <header class="flex items-center gap-2">
                    <IconNotes class="size-5 text-surface-500" />
                    <h3 class="text-sm font-semibold text-surface-900">
                        {{ t('patient.sections.notes') }}
                    </h3>
                    <Button
                        v-if="canEditNotes && !editingNotes"
                        type="button"
                        severity="secondary"
                        outlined
                        size="small"
                        class="ml-auto"
                        :label="t('patient.notes_edit')"
                        @click="startEditNotes"
                    >
                        <template #icon>
                            <IconPencil />
                        </template>
                    </Button>
                </header>

                <template v-if="editingNotes">
                    <Textarea
                        v-model="notesForm.notes"
                        rows="4"
                        auto-resize
                        fluid
                        :invalid="Boolean(notesForm.errors.notes)"
                        :placeholder="t('patient.notes_placeholder')"
                        :aria-label="t('patient.sections.notes')"
                    />
                    <small v-if="notesForm.errors.notes" class="text-red-500">
                        {{ notesForm.errors.notes }}
                    </small>
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            :label="t('common.cancel')"
                            :disabled="notesForm.processing"
                            @click="cancelEditNotes"
                        />
                        <Button
                            type="button"
                            size="small"
                            :label="t('patient.save')"
                            :loading="notesForm.processing"
                            @click="saveNotes"
                        />
                    </div>
                </template>

                <template v-else>
                    <p
                        v-if="patient.notes"
                        class="text-sm whitespace-pre-line text-surface-700"
                    >
                        {{ patient.notes }}
                    </p>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.no_notes') }}
                    </p>
                </template>
            </div>
        </div>
    </SectionCard>
</template>
