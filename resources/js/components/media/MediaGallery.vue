<script setup lang="ts">
import {
    IconDownload,
    IconFile,
    IconPhoto,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDateTime } from '@/composables/useDateTime';
import type { MediaItem } from '@/types/media';
import { formatBytes } from '@/utils/formatBytes';

// Read-only media display, reused on the treatment Show + Case rollup and (deletable) inside
// MediaUploader on Process. Images open an enlarged preview (thumb in the grid, medium in the
// dialog); documents are download-only. `deletable` adds a per-item remove button that emits
// `delete` — the parent owns confirmation + the actual request (never fires here).
const props = withDefaults(
    defineProps<{
        items: MediaItem[];
        deletable?: boolean;
    }>(),
    { deletable: false },
);

const emit = defineEmits<{ delete: [item: MediaItem] }>();

const { t } = useI18n();
const { formatDate } = useDateTime();

const images = computed(() => props.items.filter((item) => item.is_image));
const documents = computed(() => props.items.filter((item) => !item.is_image));

const preview = ref<MediaItem | null>(null);
const previewVisible = ref(false);

function openPreview(item: MediaItem): void {
    preview.value = item;
    previewVisible.value = true;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Images: thumbnail grid opening an enlarged preview. -->
        <div
            v-if="images.length"
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
        >
            <figure
                v-for="item in images"
                :key="item.id"
                class="group relative flex flex-col overflow-hidden rounded-xl border border-surface-200 bg-surface-50"
            >
                <button
                    type="button"
                    class="relative aspect-square w-full overflow-hidden"
                    :aria-label="item.caption ?? item.name"
                    @click="openPreview(item)"
                >
                    <img
                        :src="item.thumb_url ?? item.download_url"
                        :alt="item.caption ?? item.name"
                        loading="lazy"
                        class="size-full object-cover transition group-hover:scale-105"
                    />
                </button>

                <figcaption
                    v-if="item.caption"
                    class="truncate px-2 py-1.5 text-xs text-surface-600"
                    :title="item.caption"
                >
                    {{ item.caption }}
                </figcaption>

                <Button
                    v-if="deletable"
                    type="button"
                    severity="danger"
                    rounded
                    size="small"
                    class="absolute end-1.5 top-1.5 opacity-0 transition group-hover:opacity-100 focus:opacity-100"
                    :aria-label="t('media.remove')"
                    @click="emit('delete', item)"
                >
                    <template #icon>
                        <IconTrash class="size-4" />
                    </template>
                </Button>
            </figure>
        </div>

        <!-- Documents: download-only rows. -->
        <ul v-if="documents.length" class="flex flex-col gap-2">
            <li
                v-for="item in documents"
                :key="item.id"
                class="flex items-center gap-3 rounded-lg border border-surface-200 bg-surface-0 px-3 py-2"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-100 text-surface-500"
                >
                    <IconFile class="size-5" />
                </span>
                <div class="flex min-w-0 flex-col">
                    <span
                        class="truncate text-sm font-medium text-surface-800"
                        :title="item.name"
                    >
                        {{ item.name }}
                    </span>
                    <span class="text-xs text-surface-500">
                        {{ item.caption ? `${item.caption} · ` : ''
                        }}{{ formatBytes(item.size) }} ·
                        {{ formatDate(item.created_at) }}
                    </span>
                </div>
                <div class="ms-auto flex shrink-0 items-center gap-1">
                    <a :href="item.download_url" download class="inline-flex">
                        <Button
                            type="button"
                            severity="secondary"
                            text
                            size="small"
                            :aria-label="t('media.download')"
                        >
                            <template #icon>
                                <IconDownload class="size-4" />
                            </template>
                        </Button>
                    </a>
                    <Button
                        v-if="deletable"
                        type="button"
                        severity="danger"
                        text
                        size="small"
                        :aria-label="t('media.remove')"
                        @click="emit('delete', item)"
                    >
                        <template #icon>
                            <IconTrash class="size-4" />
                        </template>
                    </Button>
                </div>
            </li>
        </ul>

        <!-- Enlarged image preview. -->
        <Dialog
            v-model:visible="previewVisible"
            modal
            dismissable-mask
            :header="preview?.caption ?? preview?.name"
            :style="{ width: '90vw', maxWidth: '900px' }"
        >
            <div v-if="preview" class="flex flex-col gap-3">
                <img
                    :src="preview.preview_url ?? preview.download_url"
                    :alt="preview.caption ?? preview.name"
                    class="max-h-[70vh] w-full rounded-lg object-contain"
                />
                <div
                    class="flex items-center justify-between gap-2 text-sm text-surface-500"
                >
                    <span>
                        {{ formatBytes(preview.size) }} ·
                        {{ formatDate(preview.created_at) }}
                    </span>
                    <a :href="preview.download_url" download>
                        <Button
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            :label="t('media.download')"
                        >
                            <template #icon>
                                <IconDownload class="size-4" />
                            </template>
                        </Button>
                    </a>
                </div>
            </div>
        </Dialog>

        <!-- Empty within an already-carded section (Process gallery under the uploader). -->
        <div
            v-if="!items.length"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-surface-200 px-4 py-10 text-center text-surface-400"
        >
            <IconPhoto class="size-8" />
            <span class="text-sm">{{ t('media.empty') }}</span>
        </div>
    </div>
</template>
