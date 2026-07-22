<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { IconBookmark, IconDeviceFloppy, IconTrash } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { destroy, store } from '@/routes/patient-segments';
import type { PatientSegment, SegmentCriteria } from '@/types/patient';

const props = defineProps<{
    segments: PatientSegment[];
    /** The current queryable filter subset (search excluded) — what "save" persists. */
    currentCriteria: SegmentCriteria;
    /** Whether the current filter state has anything worth saving as a segment. */
    hasCriteria: boolean;
    /** `segments.manage` — gates save + delete (apply is always allowed). */
    canManage: boolean;
}>();

const emit = defineEmits<{ apply: [criteria: SegmentCriteria] }>();

const { t } = useI18n();
const confirm = useConfirm();

const selectedId = ref<number | null>(null);

function onSelect(id: number | null): void {
    const segment = props.segments.find((s) => s.id === id);

    if (segment) {
        emit('apply', segment.criteria);
    }

    // Reset so the control reads as an action, not a persisted selection.
    selectedId.value = null;
}

const dialogVisible = ref(false);
const form = useForm<{ name: string; criteria: SegmentCriteria }>({
    name: '',
    criteria: {},
});

function openSave(): void {
    form.clearErrors();
    form.name = '';
    dialogVisible.value = true;
}

function submitSave(): void {
    form.transform((data) => ({
        ...data,
        criteria: props.currentCriteria,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            dialogVisible.value = false;
        },
    });
}

function removeSegment(segment: PatientSegment): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('segment.delete_confirm', { name: segment.name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(segment.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Select
            v-if="segments.length"
            :model-value="selectedId"
            :options="segments"
            option-label="name"
            option-value="id"
            :placeholder="t('segment.apply')"
            class="w-full sm:w-56"
            @update:model-value="onSelect"
        >
            <template #option="{ option }">
                <div class="flex w-full items-center justify-between gap-2">
                    <span class="truncate">{{ option.name }}</span>
                    <button
                        v-if="canManage"
                        type="button"
                        class="flex shrink-0 cursor-pointer items-center text-surface-400 transition-colors hover:text-red-500"
                        :aria-label="t('segment.delete')"
                        @click.stop="removeSegment(option)"
                    >
                        <IconTrash class="size-4" />
                    </button>
                </div>
            </template>
        </Select>

        <Button
            v-if="canManage"
            type="button"
            severity="secondary"
            outlined
            :label="t('segment.save')"
            :disabled="!hasCriteria"
            @click="openSave"
        >
            <template #icon>
                <IconBookmark />
            </template>
        </Button>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :draggable="false"
            :header="t('segment.save_title')"
            class="w-full max-w-sm"
        >
            <p class="mb-4 text-sm text-surface-500">
                {{ t('segment.save_hint') }}
            </p>

            <form novalidate @submit.prevent="submitSave">
                <FormField
                    :label="t('segment.fields.name')"
                    :error="form.errors.name"
                    required
                >
                    <InputText v-model="form.name" maxlength="100" fluid />
                </FormField>

                <div class="mt-6 flex justify-end gap-2">
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        :label="t('common.cancel')"
                        @click="dialogVisible = false"
                    />
                    <Button
                        type="submit"
                        :label="t('segment.save_submit')"
                        :loading="form.processing"
                    >
                        <template #icon>
                            <IconDeviceFloppy />
                        </template>
                    </Button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
