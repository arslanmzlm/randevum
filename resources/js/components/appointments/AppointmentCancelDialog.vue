<script setup lang="ts">
import { useI18n } from 'vue-i18n';

// The cancel confirm dialog with an optional reason. Its own ConfirmDialog group lets the reason
// textarea live in the message slot; pair with useAppointmentActions().confirmCancel / cancelReason.
const reason = defineModel<string>({ required: true });

const { t } = useI18n();
</script>

<template>
    <ConfirmDialog group="appointment-cancel">
        <template #message="{ message }">
            <div class="flex w-full flex-col gap-3">
                <p class="text-sm text-surface-600">{{ message.message }}</p>
                <div class="flex flex-col gap-1">
                    <label for="cancel-reason" class="text-sm text-muted">
                        {{ t('appointment_actions.reason_label') }}
                    </label>
                    <Textarea
                        id="cancel-reason"
                        v-model="reason"
                        rows="3"
                        :placeholder="
                            t('appointment_actions.reason_placeholder')
                        "
                        :maxlength="500"
                        auto-resize
                        fluid
                    />
                </div>
            </div>
        </template>
    </ConfirmDialog>
</template>
