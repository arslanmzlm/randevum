<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { offboardPreview } from '@/actions/App/Modules/Core/Http/Controllers/DoctorController';
import FormField from '@/components/FormField.vue';
import { offboard } from '@/routes/doctors';
import type { Doctor } from '@/types/doctor';

const props = defineProps<{ doctor: Doctor | null }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

type PreviewState = 'loading' | 'loaded' | 'error';

const previewState = ref<PreviewState>('loading');
const upcomingCount = ref(0);

const form = useForm<{ cancel_appointments: boolean; reason: string }>({
    cancel_appointments: false,
    reason: '',
});

let activeRequest: AbortController | undefined;

async function loadPreview(doctor: Doctor): Promise<void> {
    activeRequest?.abort();
    activeRequest = new AbortController();
    previewState.value = 'loading';

    try {
        const response = await fetch(offboardPreview(doctor.id).url, {
            headers: { Accept: 'application/json' },
            signal: activeRequest.signal,
        });

        if (!response.ok) {
            previewState.value = 'error';

            return;
        }

        const body = (await response.json()) as {
            upcoming_appointments_count: number;
        };
        upcomingCount.value = body.upcoming_appointments_count;
        previewState.value = 'loaded';
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            previewState.value = 'error';
        }
    }
}

// Reset + (re)fetch each time the dialog opens for a doctor; abort on close.
watch(
    [visible, () => props.doctor],
    ([isOpen, doctor]) => {
        if (isOpen && doctor) {
            form.reset();
            form.clearErrors();
            upcomingCount.value = 0;
            void loadPreview(doctor);
        } else {
            activeRequest?.abort();
        }
    },
    { immediate: true },
);

function close(): void {
    visible.value = false;
}

function submit(): void {
    if (!props.doctor) {
        return;
    }

    form.post(offboard(props.doctor.id).url, {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :header="t('doctor.offboard_dialog_title')"
        :style="{ width: '30rem' }"
        :dismissable-mask="!form.processing"
    >
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-600">
                {{ t('doctor.offboard_intro', { name: doctor?.display_name }) }}
            </p>

            <div
                v-if="previewState === 'loading'"
                class="flex flex-col gap-2"
                aria-busy="true"
            >
                <Skeleton height="3rem" />
                <Skeleton height="2rem" width="60%" />
            </div>

            <Message v-else-if="previewState === 'error'" severity="error">
                {{ t('doctor.offboard_preview_error') }}
            </Message>

            <template v-else>
                <Message
                    :severity="upcomingCount > 0 ? 'warn' : 'info'"
                    :closable="false"
                >
                    <div class="flex items-start gap-2">
                        <IconAlertTriangle class="mt-0.5 size-5 shrink-0" />
                        <span class="text-sm">
                            {{
                                upcomingCount > 0
                                    ? t('doctor.offboard_warning', {
                                          count: upcomingCount,
                                      })
                                    : t('doctor.offboard_warning_none')
                            }}
                        </span>
                    </div>
                </Message>

                <template v-if="upcomingCount > 0">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-start gap-2">
                            <Checkbox
                                v-model="form.cancel_appointments"
                                input-id="offboard-cancel-appointments"
                                binary
                                :invalid="!!form.errors.cancel_appointments"
                            />
                            <label
                                for="offboard-cancel-appointments"
                                class="text-sm text-surface-700"
                            >
                                {{
                                    t('doctor.offboard_cancel_appointments', {
                                        count: upcomingCount,
                                    })
                                }}
                            </label>
                        </div>
                        <small
                            v-if="form.errors.cancel_appointments"
                            class="text-xs text-red-500"
                        >
                            {{ form.errors.cancel_appointments }}
                        </small>
                        <small v-else class="text-xs text-surface-400">
                            {{ t('doctor.offboard_cancel_hint') }}
                        </small>
                    </div>

                    <FormField
                        v-if="form.cancel_appointments"
                        :label="t('doctor.offboard_reason_label')"
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
                </template>
            </template>
        </div>

        <template #footer>
            <Button
                type="button"
                severity="secondary"
                outlined
                :label="t('common.cancel')"
                :disabled="form.processing"
                @click="close"
            />
            <Button
                type="button"
                severity="danger"
                :label="t('doctor.offboard_confirm')"
                :loading="form.processing"
                :disabled="
                    previewState !== 'loaded' ||
                    (upcomingCount > 0 && !form.cancel_appointments)
                "
                @click="submit"
            />
        </template>
    </Dialog>
</template>
