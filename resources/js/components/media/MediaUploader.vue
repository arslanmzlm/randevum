<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { IconUpload, IconX } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MediaGallery from '@/components/media/MediaGallery.vue';
import { useFileDrop } from '@/composables/useFileDrop';
import type { MediaItem } from '@/types/media';
import { formatBytes } from '@/utils/formatBytes';

// Reusable multi-file uploader. Built generic (URLs/accept/limits injected) so a future patient
// upload surface can reuse it against its own endpoints. The backend stores one file per
// request, so staged files are POSTed sequentially, each with its optional caption.
const props = withDefaults(
    defineProps<{
        /** POST endpoint that stores one uploaded file (multipart: `file` + `caption`). */
        uploadUrl: string;
        /** `accept` attribute + client MIME hint (server enforces the real allowlist). */
        accept: string;
        /** Client-side size ceiling in MB (server enforces the authoritative limit). */
        maxSizeMb: number;
        /** Files already attached — rendered below via the read-only gallery. */
        items: MediaItem[];
        canDelete?: boolean;
        /** Builds the DELETE endpoint for an item (kept out of the component). */
        deleteUrlFor?: (item: MediaItem) => string;
    }>(),
    { canDelete: false, deleteUrlFor: undefined },
);

const { t } = useI18n();
const confirm = useConfirm();

type StagedFile = {
    key: string;
    file: File;
    caption: string;
    error: string | null;
};

const fileInput = ref<HTMLInputElement | null>(null);
const staged = ref<StagedFile[]>([]);

const form = useForm<{ file: File | null; caption: string | null }>({
    file: null,
    caption: null,
});

function pickFiles(): void {
    fileInput.value?.click();
}

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;

    stage(Array.from(input.files ?? []));

    // Allow re-selecting the same file after removing it from the staging list.
    input.value = '';
}

const { isDragging, dropHandlers } = useFileDrop(stage);

/** Files land in the staging list (never uploaded straight away) so captions can be typed first. */
function stage(files: File[]): void {
    const maxBytes = props.maxSizeMb * 1024 * 1024;

    for (const file of files) {
        staged.value.push({
            key: `${file.name}-${file.size}-${crypto.randomUUID()}`,
            file,
            caption: '',
            error:
                file.size > maxBytes
                    ? t('media.too_large', { max: props.maxSizeMb })
                    : null,
        });
    }
}

function removeStaged(key: string): void {
    staged.value = staged.value.filter((entry) => entry.key !== key);
}

// One request per file (backend stores a single file each call); stop on the first failure so
// the offending row keeps its server error while the rest stay staged for a retry.
function uploadNext(): void {
    const entry = staged.value.find((item) => !item.error);

    if (!entry) {
        return;
    }

    form.file = entry.file;
    form.caption = entry.caption.trim() === '' ? null : entry.caption.trim();

    form.post(props.uploadUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            removeStaged(entry.key);
            form.reset();
            uploadNext();
        },
        onError: (errors) => {
            entry.error =
                errors.file ?? errors.caption ?? t('common.form_error');
        },
    });
}

function requestDelete(item: MediaItem): void {
    if (!props.deleteUrlFor) {
        return;
    }

    const deleteUrl = props.deleteUrlFor(item);

    confirm.require({
        header: t('common.confirm_title'),
        message: t('media.confirm_delete'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () => {
            router.delete(deleteUrl, { preserveScroll: true });
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <input
            ref="fileInput"
            type="file"
            multiple
            :accept="accept"
            class="hidden"
            @change="onFileChange"
        />

        <!-- Staged files awaiting upload — each with an optional caption. -->
        <ul v-if="staged.length" class="flex flex-col gap-2">
            <li
                v-for="entry in staged"
                :key="entry.key"
                class="flex flex-col gap-2 rounded-lg border border-surface-200 bg-surface-50 p-3 sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-sm font-medium text-surface-800"
                        :title="entry.file.name"
                    >
                        {{ entry.file.name }}
                    </span>
                    <span class="text-xs text-surface-500">
                        {{ formatBytes(entry.file.size) }}
                    </span>
                    <small v-if="entry.error" class="text-xs text-red-500">
                        {{ entry.error }}
                    </small>
                </div>
                <InputText
                    v-model="entry.caption"
                    :placeholder="t('media.caption_placeholder')"
                    :maxlength="255"
                    size="small"
                    class="w-full sm:w-64"
                />
                <Button
                    type="button"
                    severity="secondary"
                    text
                    size="small"
                    :aria-label="t('common.cancel')"
                    @click="removeStaged(entry.key)"
                >
                    <template #icon>
                        <IconX class="size-4" />
                    </template>
                </Button>
            </li>
        </ul>

        <!-- Drop zone doubles as the picker: clicking anywhere in it opens the file dialog. -->
        <div
            class="flex cursor-pointer flex-col items-center gap-3 rounded-xl border-2 border-dashed p-6 text-center transition-colors"
            :class="
                isDragging
                    ? 'border-primary-400 bg-primary-50'
                    : 'border-surface-200 bg-surface-50 hover:border-surface-300'
            "
            v-on="dropHandlers"
            @click="pickFiles"
        >
            <IconUpload
                class="size-6"
                :class="isDragging ? 'text-primary-500' : 'text-surface-400'"
            />
            <p class="text-sm text-surface-600">
                {{
                    isDragging ? t('media.drop_hint') : t('media.drop_or_pick')
                }}
            </p>
            <p class="text-xs text-surface-400">
                {{ t('media.formats_hint') }} ·
                {{ t('media.max_size_hint', { max: maxSizeMb }) }}
            </p>
        </div>

        <div v-if="staged.some((entry) => !entry.error)" class="flex">
            <Button
                type="button"
                :label="t('media.upload')"
                :loading="form.processing"
                @click="uploadNext"
            >
                <template #icon>
                    <IconUpload class="size-4" />
                </template>
            </Button>
        </div>

        <!-- Current files — deletable in place (parent-supplied DELETE endpoint). -->
        <MediaGallery
            :items="items"
            :deletable="canDelete && !!deleteUrlFor"
            @delete="requestDelete"
        />
    </div>
</template>
