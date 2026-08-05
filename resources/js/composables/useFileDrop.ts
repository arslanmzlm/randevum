import { ref } from 'vue';

/**
 * Drag-and-drop file handling for an upload surface: the drop target's listeners plus the
 * `isDragging` flag the template highlights on. Validation stays with the caller — this only
 * turns a drop into a File list.
 *
 * dragenter/dragleave also fire while the pointer moves between child elements, so the counter
 * keeps the highlight steady instead of flickering over every label and button inside the zone.
 */
export function useFileDrop(onFiles: (files: File[]) => void) {
    const isDragging = ref(false);
    let depth = 0;

    function carriesFiles(event: DragEvent): boolean {
        return Array.from(event.dataTransfer?.types ?? []).includes('Files');
    }

    function dragenter(event: DragEvent): void {
        if (!carriesFiles(event)) {
            return;
        }

        depth += 1;
        isDragging.value = true;
    }

    function dragover(event: DragEvent): void {
        if (!carriesFiles(event)) {
            return;
        }

        // Without this the browser opens the file instead of handing it over.
        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    function dragleave(): void {
        depth = Math.max(0, depth - 1);

        if (depth === 0) {
            isDragging.value = false;
        }
    }

    function drop(event: DragEvent): void {
        if (!carriesFiles(event)) {
            return;
        }

        event.preventDefault();
        depth = 0;
        isDragging.value = false;

        const files = Array.from(event.dataTransfer?.files ?? []);

        if (files.length) {
            onFiles(files);
        }
    }

    // Keys are event NAMES: `v-on="dropHandlers"` binds by name, not by the onXxx prop form.
    return {
        isDragging,
        dropHandlers: { dragenter, dragover, dragleave, drop },
    };
}
