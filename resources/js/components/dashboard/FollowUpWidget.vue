<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    IconNote,
    IconPhone,
    IconPhoneOff,
    IconPhoneCheck,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { dismiss } from '@/routes/cases/follow-up';
import { show as patientShow } from '@/routes/patients';
import type { FollowUpReminder } from '@/types/dashboard';

const props = defineProps<{ followUps: FollowUpReminder[] }>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateOnly } = useDateTime();
const confirm = useConfirm();

const canDismiss = () => can('followUps.dismiss');

// Notes can run long; keep the cell to a one-line preview and reveal the full text in a single
// reused Popover anchored to the clicked trigger (works on touch, scrolls for very long notes).
const notePopover = ref();
const activeNote = ref('');

function showNote(event: Event, note: string): void {
    activeNote.value = note;
    notePopover.value?.show(event);
}

function markCalled(row: FollowUpReminder): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('dashboard.follow_ups.dismiss_confirm', {
            name: row.patient.full_name,
        }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('dashboard.follow_ups.dismiss') },
        accept: () =>
            router.delete(dismiss(row.case_id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <DashboardPanel>
        <template #title>
            <IconPhone class="size-5 text-primary-500" />
            <h2 class="text-base font-semibold text-surface-900">
                {{ t('dashboard.follow_ups.title') }}
            </h2>
            <Badge
                v-if="props.followUps.length"
                :value="props.followUps.length"
                severity="secondary"
            />
        </template>

        <div
            v-if="!props.followUps.length"
            class="flex flex-col items-center justify-center gap-2 px-5 py-10 text-center"
        >
            <IconPhoneOff class="size-8 text-surface-300" />
            <p class="text-sm text-surface-500">
                {{ t('dashboard.follow_ups.empty') }}
            </p>
        </div>

        <div v-else>
            <DataTable
                :value="props.followUps"
                data-key="case_id"
                size="small"
                class="text-sm"
            >
                <Column :header="t('dashboard.follow_ups.columns.patient')">
                    <template #body="{ data }">
                        <Link
                            :href="patientShow(data.patient.id).url"
                            class="font-medium text-primary-600 transition-colors hover:text-primary-700"
                        >
                            {{ data.patient.full_name }}
                        </Link>
                    </template>
                </Column>

                <Column :header="t('dashboard.follow_ups.columns.phone')">
                    <template #body="{ data }">
                        <a
                            v-if="data.patient.phone"
                            :href="`tel:${data.patient.phone}`"
                            class="inline-flex items-center gap-1 text-surface-700 transition-colors hover:text-primary-600"
                        >
                            <IconPhone class="size-4 text-surface-400" />
                            {{ data.patient.phone }}
                        </a>
                        <span v-else class="text-surface-400">
                            {{ t('dashboard.follow_ups.no_phone') }}
                        </span>
                    </template>
                </Column>

                <Column :header="t('dashboard.follow_ups.columns.doctor')">
                    <template #body="{ data }">
                        <span class="text-surface-700">
                            {{ data.doctor.display_name }}
                        </span>
                    </template>
                </Column>

                <Column
                    :header="t('dashboard.follow_ups.columns.date')"
                    class="w-40"
                >
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <span
                                :class="
                                    data.is_overdue
                                        ? 'font-medium text-red-600'
                                        : 'text-surface-700'
                                "
                            >
                                {{ formatDateOnly(data.follow_up_date) }}
                            </span>
                            <Tag
                                v-if="data.is_overdue"
                                :value="t('dashboard.follow_ups.overdue')"
                                severity="danger"
                            />
                        </div>
                    </template>
                </Column>

                <Column :header="t('dashboard.follow_ups.columns.note')">
                    <template #body="{ data }">
                        <button
                            v-if="data.follow_up_note"
                            type="button"
                            class="flex max-w-[14rem] cursor-pointer items-center gap-1 text-left text-surface-600 transition-colors hover:text-primary-600"
                            @click="showNote($event, data.follow_up_note)"
                        >
                            <IconNote
                                class="size-4 shrink-0 text-surface-400"
                            />
                            <span class="truncate">{{
                                data.follow_up_note
                            }}</span>
                        </button>
                        <span v-else class="text-surface-400">—</span>
                    </template>
                </Column>

                <Column v-if="canDismiss()" class="w-32 text-right">
                    <template #body="{ data }">
                        <Button
                            :label="t('dashboard.follow_ups.dismiss')"
                            severity="secondary"
                            size="small"
                            outlined
                            @click="markCalled(data)"
                        >
                            <template #icon>
                                <IconPhoneCheck class="size-4" />
                            </template>
                        </Button>
                    </template>
                </Column>
            </DataTable>
        </div>

        <Popover ref="notePopover">
            <p class="max-w-xs text-sm whitespace-pre-line text-surface-700">
                {{ activeNote }}
            </p>
        </Popover>
    </DashboardPanel>
</template>
