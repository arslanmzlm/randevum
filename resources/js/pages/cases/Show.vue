<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import CaseFollowUpCard from '@/components/cases/CaseFollowUpCard.vue';
import CaseInfoCard from '@/components/cases/CaseInfoCard.vue';
import CaseMediaCard from '@/components/cases/CaseMediaCard.vue';
import CaseNotesCard from '@/components/cases/CaseNotesCard.vue';
import {
    CASE_ACTION_SEVERITY,
    caseTransitionLabel,
} from '@/components/cases/caseTransitions';
import CaseTreatmentsList from '@/components/cases/CaseTreatmentsList.vue';
import FollowUpStatusDialog from '@/components/cases/FollowUpStatusDialog.vue';
import LinkTreatmentsDialog from '@/components/cases/LinkTreatmentsDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/cases';
import { update as updateStatus } from '@/routes/cases/status';
import type { CaseShowProps } from '@/types/case';
import type { CaseStatus } from '@/types/enums';

defineOptions({ layout: AppLayout });

const props = defineProps<CaseShowProps>();

// `case` is a JS reserved word, so the `case` prop cannot be referenced bare in the template.
const caseRecord = computed(() => props.case);

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();

// Mirror the server policy: mutate any clinic case with viewAll, else only own.
const canManage = computed(
    () =>
        can('cases.update') &&
        (can('cases.viewAll') || props.case.doctor.id === props.ownDoctorId),
);

const canLink = computed(() => canManage.value && props.case.status === 'open');

const showFollowUpStatusDialog = ref(false);
const showLinkDialog = ref(false);

function transitionLabel(target: CaseStatus): string {
    return caseTransitionLabel(t, target);
}

function submitStatus(target: CaseStatus): void {
    router.patch(
        updateStatus(props.case.id).url,
        { status: target },
        { preserveScroll: true },
    );
}

function onTransition(target: CaseStatus): void {
    // → Follow-up requires a date, so collect it in a dedicated dialog instead of a bare confirm.
    if (target === 'follow_up') {
        showFollowUpStatusDialog.value = true;

        return;
    }

    confirm.require({
        header: t('case.confirm.status_title'),
        message: t(`case.confirm.status_${target}`),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: transitionLabel(target),
            severity: CASE_ACTION_SEVERITY[target],
        },
        accept: () => submitStatus(target),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="caseRecord.title" />

        <PageHeader
            :title="caseRecord.title"
            :breadcrumbs="[
                { label: t('nav.cases'), href: index().url },
                { label: caseRecord.title },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('case.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
                <template v-if="canManage">
                    <Button
                        v-for="target in allowedTransitions"
                        :key="target"
                        type="button"
                        :severity="CASE_ACTION_SEVERITY[target]"
                        :outlined="target !== 'closed'"
                        :label="transitionLabel(target)"
                        @click="onTransition(target)"
                    />
                </template>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <CaseInfoCard
                    :case-record="caseRecord"
                    :can-manage="canManage"
                    :can-edit-title="canEditTitle"
                />

                <CaseTreatmentsList
                    :case-record="caseRecord"
                    :can-link="canLink"
                    :ungrouped-count="ungroupedTreatments.length"
                    @open-link="showLinkDialog = true"
                />

                <CaseMediaCard
                    v-if="caseRecord.media?.length"
                    :items="caseRecord.media"
                />
            </div>

            <div class="flex flex-col gap-6">
                <CaseNotesCard
                    :case-record="caseRecord"
                    :can-manage="canManage"
                />

                <CaseFollowUpCard
                    :case-record="caseRecord"
                    :can-manage="canManage"
                />
            </div>
        </div>

        <FollowUpStatusDialog
            v-model:visible="showFollowUpStatusDialog"
            :case-record="caseRecord"
        />

        <LinkTreatmentsDialog
            v-model:visible="showLinkDialog"
            :case-id="caseRecord.id"
            :ungrouped-treatments="ungroupedTreatments"
        />
    </div>
</template>
