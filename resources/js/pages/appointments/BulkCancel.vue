<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconCalendarOff,
    IconInfoCircle,
    IconLoader2,
    IconUsers,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { bulkCancel } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import BulkCancelTable from '@/components/appointments/BulkCancelTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useBulkCancelPreview } from '@/composables/useBulkCancelPreview';
import type { BulkCancelPreviewParams } from '@/composables/useBulkCancelPreview';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/appointments';
import type { BulkCancelProps } from '@/types/appointment';
import { toDateString } from '@/utils/datetime';
import { shouldFilterSelect } from '@/utils/selectFilter';

defineOptions({ layout: AppLayout });

defineProps<BulkCancelProps>();

const { t } = useI18n();
const confirm = useConfirm();

// Closing a past day has no useful effect (those appointments are terminal), so the pickers
// start at today; the end picker can't precede the chosen start.
const minStart = new Date();

const form = useForm<{
    start_date: Date | null;
    end_date: Date | null;
    doctor_id: number | null;
    reason: string;
    block_new_bookings: boolean;
}>({
    start_date: null,
    end_date: null,
    doctor_id: null,
    reason: '',
    block_new_bookings: false,
});

// Live preview probe: only resolved once both clinic-local days are picked. doctor_id null = all.
const previewParams = computed<BulkCancelPreviewParams | null>(() => {
    if (!form.start_date || !form.end_date) {
        return null;
    }

    return {
        start_date: toDateString(form.start_date),
        end_date: toDateString(form.end_date),
        doctor_id: form.doctor_id,
    };
});

const { state, count, rows } = useBulkCancelPreview(() => previewParams.value);

const canSubmit = computed(
    () =>
        previewParams.value !== null &&
        state.value === 'loaded' &&
        count.value > 0 &&
        !form.processing,
);

function submit(): void {
    confirm.require({
        header: t('appointment_bulk_cancel.confirm_title'),
        message: t(
            'appointment_bulk_cancel.confirm_message',
            { count: count.value },
            count.value,
        ),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: t('appointment_bulk_cancel.submit'),
            severity: 'danger',
        },
        accept: () => {
            form.transform((data) => ({
                start_date: data.start_date
                    ? toDateString(data.start_date)
                    : null,
                end_date: data.end_date ? toDateString(data.end_date) : null,
                doctor_id: data.doctor_id,
                reason: data.reason || null,
                block_new_bookings: data.block_new_bookings,
            })).post(bulkCancel().url, { preserveScroll: true });
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_bulk_cancel.title')" />

        <PageHeader
            :title="t('appointment_bulk_cancel.title')"
            :description="t('appointment_bulk_cancel.subtitle')"
            :breadcrumbs="[
                { label: t('appointment_list.title'), href: index().url },
                { label: t('appointment_bulk_cancel.title') },
            ]"
        />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <!-- Criteria -->
            <form
                novalidate
                class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8 lg:col-span-2"
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField
                        :label="t('appointment_bulk_cancel.start_date')"
                        :error="form.errors.start_date"
                        required
                    >
                        <DatePicker
                            v-model="form.start_date"
                            date-format="dd.mm.yy"
                            :min-date="minStart"
                            :manual-input="false"
                            show-button-bar
                            fluid
                        />
                    </FormField>

                    <FormField
                        :label="t('appointment_bulk_cancel.end_date')"
                        :error="form.errors.end_date"
                        required
                    >
                        <DatePicker
                            v-model="form.end_date"
                            date-format="dd.mm.yy"
                            :min-date="form.start_date ?? minStart"
                            :manual-input="false"
                            show-button-bar
                            fluid
                        />
                    </FormField>
                </div>

                <FormField
                    v-if="doctors.length"
                    :label="t('appointment_bulk_cancel.doctor')"
                    :error="form.errors.doctor_id"
                    :hint="t('appointment_bulk_cancel.all_doctors_hint')"
                >
                    <Select
                        v-model="form.doctor_id"
                        :options="doctors"
                        option-label="display_name"
                        option-value="id"
                        show-clear
                        :filter="shouldFilterSelect(doctors.length)"
                        :filter-placeholder="t('common.search')"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('appointment_bulk_cancel.reason')"
                    :error="form.errors.reason"
                >
                    <Textarea
                        v-model="form.reason"
                        rows="3"
                        :maxlength="500"
                        auto-resize
                        fluid
                    />
                </FormField>

                <div
                    class="flex flex-col gap-2 rounded-lg border border-surface-200 p-4"
                >
                    <div class="flex items-center justify-between gap-4">
                        <label
                            for="block-new-bookings"
                            class="text-sm font-medium text-surface-900"
                        >
                            {{
                                t('appointment_bulk_cancel.block_new_bookings')
                            }}
                        </label>
                        <ToggleSwitch
                            v-model="form.block_new_bookings"
                            input-id="block-new-bookings"
                        />
                    </div>
                    <p
                        class="flex items-start gap-1.5 text-xs text-surface-500"
                    >
                        <IconInfoCircle class="mt-0.5 size-3.5 shrink-0" />
                        {{
                            t('appointment_bulk_cancel.block_new_bookings_hint')
                        }}
                    </p>
                </div>

                <Button
                    type="submit"
                    severity="danger"
                    :label="t('appointment_bulk_cancel.submit')"
                    :loading="form.processing"
                    :disabled="!canSubmit"
                    class="w-full"
                >
                    <template #icon>
                        <IconCalendarOff />
                    </template>
                </Button>
            </form>

            <!-- Preview -->
            <section
                class="flex flex-col rounded-xl border border-surface-200 bg-surface-0 lg:col-span-3"
            >
                <header
                    class="flex items-center justify-between gap-3 border-b border-surface-200 px-5 py-4"
                >
                    <h2 class="text-base font-semibold text-surface-900">
                        {{ t('appointment_bulk_cancel.preview_title') }}
                    </h2>
                    <span
                        v-if="state === 'loaded' && count > 0"
                        class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-sm font-medium text-red-600"
                    >
                        <IconUsers class="size-4" />
                        {{
                            t(
                                'appointment_bulk_cancel.affected_count',
                                { count },
                                count,
                            )
                        }}
                    </span>
                </header>

                <!-- Idle: no range chosen yet -->
                <EmptyState
                    v-if="state === 'idle'"
                    :icon="IconCalendarOff"
                    :message="t('appointment_bulk_cancel.pick_range')"
                    :bordered="false"
                    class="flex-1"
                />

                <!-- Loading -->
                <div
                    v-else-if="state === 'loading'"
                    class="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-16 text-center"
                >
                    <IconLoader2 class="size-7 animate-spin text-surface-300" />
                    <p class="text-sm text-surface-500">
                        {{ t('appointment_bulk_cancel.loading') }}
                    </p>
                </div>

                <!-- Empty: range chosen, nothing cancellable -->
                <EmptyState
                    v-else-if="count === 0"
                    :icon="IconCalendarOff"
                    :message="t('appointment_bulk_cancel.empty')"
                    :bordered="false"
                    class="flex-1"
                />

                <!-- Affected appointments -->
                <BulkCancelTable v-else :appointments="rows" />
            </section>
        </div>
    </div>
</template>
