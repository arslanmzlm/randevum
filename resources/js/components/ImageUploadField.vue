<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconPhoto, IconTrash, IconUpload } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFileDrop } from '@/composables/useFileDrop';

const props = defineProps<{
    /** Current image URL, or null when none is set. */
    url: string | null;
    label: string;
    hint?: string;
    /** Tailwind aspect-ratio class for the preview tile (e.g. `aspect-video`). */
    aspectClass: string;
    /** POST endpoint that stores the uploaded image (multipart). */
    uploadUrl: string;
    /** DELETE endpoint that clears the image. */
    removeUrl: string;
    /** Confirm-dialog message shown before removing. */
    removeConfirm: string;
}>();

const { t } = useI18n();
const confirm = useConfirm();

const fileInput = ref<HTMLInputElement | null>(null);
const uploadForm = useForm<{ image: File | null }>({ image: null });
const removeForm = useForm({});

function pickFile(): void {
    fileInput.value?.click();
}

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    upload(file);
}

// One image per field, so a multi-file drop takes the first.
const { isDragging, dropHandlers } = useFileDrop((files) => upload(files[0]));

function upload(file: File): void {
    uploadForm.image = file;
    uploadForm.post(props.uploadUrl, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            uploadForm.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function remove(): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: props.removeConfirm,
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () => {
            removeForm.delete(props.removeUrl, {
                preserveScroll: true,
            });
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <span class="text-sm font-medium text-surface-900">{{ label }}</span>

        <!-- The preview tile is the drop zone and doubles as the picker, so the whole thing is
             clickable rather than only the button below. -->
        <div
            class="relative w-full max-w-xs cursor-pointer overflow-hidden rounded-xl border bg-surface-50 transition-colors"
            :class="[
                aspectClass,
                isDragging
                    ? 'border-2 border-dashed border-primary-400 bg-primary-50'
                    : 'border-surface-200',
            ]"
            v-on="dropHandlers"
            @click="pickFile"
        >
            <img
                v-if="url"
                :src="url"
                :alt="label"
                class="size-full object-cover"
            />
            <div
                v-else
                class="flex size-full flex-col items-center justify-center gap-2 text-surface-400"
            >
                <IconPhoto class="size-8" />
                <span class="text-xs">{{ t('common.media.empty') }}</span>
            </div>

            <div
                v-if="uploadForm.processing"
                class="absolute inset-0 flex items-center justify-center bg-surface-0/60"
            >
                <span class="text-sm text-surface-600">{{
                    t('common.media.uploading')
                }}</span>
            </div>

            <div
                v-else-if="isDragging"
                class="absolute inset-0 flex items-center justify-center bg-primary-50/80"
            >
                <span class="text-sm font-medium text-primary-700">
                    {{ t('common.media.drop_hint') }}
                </span>
            </div>
        </div>

        <p v-if="hint" class="text-xs text-surface-400">{{ hint }}</p>
        <small v-if="uploadForm.errors.image" class="text-xs text-red-500">{{
            uploadForm.errors.image
        }}</small>

        <div class="flex items-center gap-2">
            <input
                ref="fileInput"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="hidden"
                @change="onFileChange"
            />
            <Button
                type="button"
                severity="secondary"
                size="small"
                :loading="uploadForm.processing"
                @click="pickFile"
            >
                <IconUpload />
                {{ t('common.media.upload') }}
            </Button>
            <Button
                v-if="url"
                type="button"
                severity="danger"
                text
                size="small"
                :loading="removeForm.processing"
                @click="remove"
            >
                <IconTrash />
                {{ t('common.media.remove') }}
            </Button>
        </div>
    </div>
</template>
