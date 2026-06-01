<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCalendarEvent,
    IconMail,
    IconNotes,
    IconPencil,
    IconPhone,
    IconTrash,
    IconUser,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, edit, index } from '@/routes/patients';
import type { PatientShowProps } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientShowProps>();

const { t, locale } = useI18n();
const confirm = useConfirm();

const dateFormatter = new Intl.DateTimeFormat(locale.value, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const birthDateLabel = computed(() => {
    if (!props.patient.birth_date) {
        return t('patient.not_specified');
    }

    const [year, month, day] = props.patient.birth_date.split('-').map(Number);
    const formatted = dateFormatter.format(new Date(year, month - 1, day));

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

function removePatient(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('patient.remove_confirm', { name: props.patient.full_name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () => router.delete(destroy(props.patient.id).url),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="patient.full_name" />

        <PageHeader
            :title="patient.full_name"
            :description="t('patient.detail_subtitle')"
            :breadcrumbs="[
                { label: t('nav.patients'), href: index().url },
                { label: patient.full_name },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('patient.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft class="size-4" />
                    </template>
                </ButtonLink>
                <ButtonLink
                    v-if="canManage"
                    :href="edit(patient.id).url"
                    :label="t('patient.edit')"
                >
                    <template #icon>
                        <IconPencil class="size-4" />
                    </template>
                </ButtonLink>
                <Button
                    v-if="canManage"
                    type="button"
                    severity="danger"
                    outlined
                    :label="t('patient.remove')"
                    @click="removePatient"
                >
                    <template #icon>
                        <IconTrash class="size-4" />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section
                class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8 lg:col-span-2"
            >
                <header class="flex items-center gap-2">
                    <IconUser class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.profile') }}
                    </h2>
                    <Tag
                        v-if="patient.is_legacy"
                        severity="warn"
                        :value="t('patient.legacy_badge')"
                        class="ml-auto"
                    />
                </header>

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
                            patient.notification_enabled
                                ? 'success'
                                : 'secondary'
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
                    </header>
                    <p
                        v-if="patient.notes"
                        class="text-sm whitespace-pre-line text-surface-700"
                    >
                        {{ patient.notes }}
                    </p>
                    <p v-else class="text-sm text-surface-400">
                        {{ t('patient.no_notes') }}
                    </p>
                </div>
            </section>

            <section
                class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="flex items-center gap-2">
                    <IconNotes class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('patient.sections.treatments') }}
                    </h2>
                </header>

                <div
                    class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-surface-200 px-4 py-10 text-center"
                >
                    <IconNotes class="size-8 text-surface-300" />
                    <p class="text-sm text-surface-500">
                        {{ t('patient.no_treatments') }}
                    </p>
                    <p class="text-xs text-surface-400">
                        {{ t('patient.no_treatments_hint') }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
