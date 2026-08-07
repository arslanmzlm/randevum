<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconCalendarEvent,
    IconHistory,
    IconPlus,
    IconX,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import FollowUpCompleteDialog from '@/components/follow-ups/FollowUpCompleteDialog.vue';
import FollowUpFormDialog from '@/components/follow-ups/FollowUpFormDialog.vue';
import FollowUpStatusTag from '@/components/follow-ups/FollowUpStatusTag.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { cancel } from '@/routes/follow-ups';
import type { CaseDetail } from '@/types/case';
import type { CaseFollowUpItem, FollowUpTypeOption } from '@/types/followUp';

const props = defineProps<{
    caseRecord: CaseDetail;
    /** Mirrors FollowUpPolicy::complete — followUps.dismiss, or cases.update on an own case. */
    canComplete: boolean;
    followUpTypes: FollowUpTypeOption[];
}>();

const { t } = useI18n();
const { can } = useCan();
const { formatDateOnly, formatDate } = useDateTime();
const confirm = useConfirm();

const canCreate = computed(() => can('followUps.create'));

const openFollowUps = computed(() =>
    props.caseRecord.follow_ups.filter((item) => item.status === 'open'),
);
const historyFollowUps = computed(() =>
    props.caseRecord.follow_ups.filter((item) => item.status !== 'open'),
);

const showHistory = ref(false);
const showCreateDialog = ref(false);
const showCompleteDialog = ref(false);
const completing = ref<CaseFollowUpItem | null>(null);

function complete(item: CaseFollowUpItem): void {
    completing.value = item;
    showCompleteDialog.value = true;
}

function confirmCancel(item: CaseFollowUpItem): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('follow_up.confirm.cancel'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: t('follow_up.actions.cancel'),
            severity: 'danger',
        },
        accept: () =>
            router.patch(cancel(item.id).url, {}, { preserveScroll: true }),
    });
}
</script>

<template>
    <SectionCard
        :icon="IconCalendarEvent"
        :title="t('case.sections.follow_up')"
    >
        <template #actions>
            <Button
                v-if="canCreate"
                type="button"
                severity="secondary"
                outlined
                size="small"
                :label="t('follow_up.actions.add')"
                @click="showCreateDialog = true"
            >
                <template #icon>
                    <IconPlus class="size-4" />
                </template>
            </Button>
        </template>

        <ul v-if="openFollowUps.length" class="flex flex-col gap-2">
            <li
                v-for="item in openFollowUps"
                :key="item.id"
                class="flex flex-col gap-2 rounded-xl border border-surface-200 p-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-surface-900">
                        {{ formatDateOnly(item.due_date) }}
                    </span>
                    <FollowUpStatusTag
                        :status="item.status"
                        :is-overdue="item.is_overdue"
                        small
                    />
                    <Tag
                        v-if="item.type"
                        :value="item.type.name"
                        severity="secondary"
                        class="whitespace-nowrap"
                    />
                    <span v-else class="text-xs text-surface-400">
                        {{ t('follow_up.no_type') }}
                    </span>
                </div>

                <p
                    v-if="item.note"
                    class="text-sm whitespace-pre-line text-surface-600"
                >
                    {{ item.note }}
                </p>

                <div v-if="canComplete" class="flex justify-end gap-2">
                    <Button
                        type="button"
                        severity="danger"
                        text
                        size="small"
                        :label="t('follow_up.actions.cancel')"
                        @click="confirmCancel(item)"
                    >
                        <template #icon>
                            <IconX class="size-4" />
                        </template>
                    </Button>
                    <Button
                        type="button"
                        severity="secondary"
                        outlined
                        size="small"
                        :label="t('follow_up.actions.complete')"
                        @click="complete(item)"
                    />
                </div>
            </li>
        </ul>

        <EmptyState
            v-else
            variant="dashed"
            :icon="IconCalendarEvent"
            :message="t('case.no_follow_up')"
        />

        <template v-if="historyFollowUps.length">
            <button
                type="button"
                class="mt-4 flex w-full cursor-pointer items-center gap-2 text-xs font-medium text-surface-500 transition-colors hover:text-primary-600"
                :aria-expanded="showHistory"
                @click="showHistory = !showHistory"
            >
                <IconHistory class="size-4" />
                {{
                    t('follow_up.sections.history', {
                        count: historyFollowUps.length,
                    })
                }}
            </button>

            <ul v-if="showHistory" class="mt-2 flex flex-col gap-2">
                <li
                    v-for="item in historyFollowUps"
                    :key="item.id"
                    class="flex flex-col gap-1 rounded-lg bg-surface-50 p-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-surface-500">
                            {{ formatDateOnly(item.due_date) }}
                        </span>
                        <FollowUpStatusTag :status="item.status" small />
                        <Tag
                            v-if="item.type"
                            :value="item.type.name"
                            severity="secondary"
                            class="p-tag-sm whitespace-nowrap"
                        />
                    </div>
                    <p
                        v-if="item.result_note"
                        class="text-sm whitespace-pre-line text-surface-700"
                    >
                        {{ item.result_note }}
                    </p>
                    <p
                        v-if="item.completed_at"
                        class="text-xs text-surface-400"
                    >
                        {{
                            t('follow_up.completed_meta', {
                                name: item.completed_by ?? '—',
                                date: formatDate(item.completed_at),
                            })
                        }}
                    </p>
                </li>
            </ul>
        </template>

        <FollowUpCompleteDialog
            v-model:visible="showCompleteDialog"
            :follow-up-id="completing?.id ?? null"
            :patient-name="caseRecord.patient.full_name"
        />

        <FollowUpFormDialog
            v-if="canCreate"
            v-model:visible="showCreateDialog"
            :types="followUpTypes"
            :patient="caseRecord.patient"
            :case-id="caseRecord.id"
        />
    </SectionCard>
</template>
