<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconNote,
    IconPhone,
    IconPhoneOff,
    IconPhoneCheck,
    IconPlus,
} from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue';
import FollowUpCompleteDialog from '@/components/follow-ups/FollowUpCompleteDialog.vue';
import FollowUpFormDialog from '@/components/follow-ups/FollowUpFormDialog.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { show as patientShow } from '@/routes/patients';
import type { FollowUpReminder } from '@/types/dashboard';
import type { FollowUpTypeOption } from '@/types/followUp';

defineProps<{
    followUps: FollowUpReminder[];
    types: FollowUpTypeOption[];
}>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateOnly } = useDateTime();

const canDismiss = () => can('followUps.dismiss');
const canCreate = () => can('followUps.create');

// Notes can run long; keep the cell to a one-line preview and reveal the full text in a single
// reused Popover anchored to the clicked trigger (works on touch, scrolls for very long notes).
const notePopover = ref();
const activeNote = ref('');

function showNote(event: Event, note: string): void {
    activeNote.value = note;
    notePopover.value?.show(event);
}

const showCompleteDialog = ref(false);
const showCreateDialog = ref(false);
const completing = ref<FollowUpReminder | null>(null);

function markCalled(row: FollowUpReminder): void {
    completing.value = row;
    showCompleteDialog.value = true;
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
                v-if="followUps.length"
                :value="followUps.length"
                severity="secondary"
            />
        </template>

        <template #actions>
            <Button
                v-if="canCreate()"
                type="button"
                severity="secondary"
                outlined
                size="small"
                :label="t('dashboard.follow_ups.add')"
                @click="showCreateDialog = true"
            >
                <template #icon>
                    <IconPlus class="size-4" />
                </template>
            </Button>
        </template>

        <div
            v-if="!followUps.length"
            class="flex flex-col items-center justify-center gap-2 px-5 py-10 text-center"
        >
            <IconPhoneOff class="size-8 text-surface-300" />
            <p class="text-sm text-surface-500">
                {{ t('dashboard.follow_ups.empty') }}
            </p>
        </div>

        <div v-else>
            <DataTable
                :value="followUps"
                data-key="id"
                size="small"
                class="text-sm"
            >
                <Column :header="t('dashboard.follow_ups.columns.patient')">
                    <template #body="{ data }">
                        <Link
                            :href="patientShow(data.patient.id).url"
                            class="font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
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

                <Column
                    :header="t('dashboard.follow_ups.columns.type')"
                    class="w-44"
                >
                    <template #body="{ data }">
                        <Tag
                            v-if="data.type"
                            :value="data.type.name"
                            severity="secondary"
                            class="whitespace-nowrap"
                        />
                        <span v-else class="text-surface-400">—</span>
                    </template>
                </Column>

                <Column :header="t('dashboard.follow_ups.columns.doctor')">
                    <template #body="{ data }">
                        <span v-if="data.doctor" class="text-surface-700">
                            {{ data.doctor.display_name }}
                        </span>
                        <span v-else class="text-surface-400">—</span>
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
                                {{ formatDateOnly(data.due_date) }}
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
                            v-if="data.note"
                            type="button"
                            class="flex max-w-[14rem] cursor-pointer items-center gap-1 text-left text-surface-600 transition-colors hover:text-primary-600"
                            @click="showNote($event, data.note)"
                        >
                            <IconNote
                                class="size-4 shrink-0 text-surface-400"
                            />
                            <span class="truncate">{{ data.note }}</span>
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

        <FollowUpCompleteDialog
            v-model:visible="showCompleteDialog"
            :follow-up-id="completing?.id ?? null"
            :patient-name="completing?.patient.full_name"
        />

        <FollowUpFormDialog
            v-if="canCreate()"
            v-model:visible="showCreateDialog"
            :types="types"
        />
    </DashboardPanel>
</template>
