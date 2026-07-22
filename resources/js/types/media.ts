/**
 * Shared media-item shape (mirrors App\Modules\Medical\Support\MediaItemMapper).
 * Every URL is a same-origin authorized streaming route — never a public/getUrl() link.
 * Built generic so future patient upload and viewing surfaces reuse it.
 */
export type MediaItem = {
    id: number;
    name: string;
    caption: string | null;
    mime: string;
    size: number;
    is_image: boolean;
    /** Thumbnail stream URL for images; null for documents. */
    thumb_url: string | null;
    /** Larger preview stream URL for images; null for documents. */
    preview_url: string | null;
    /** Forces an attachment download for any file type. */
    download_url: string;
    /** ISO 8601 UTC timestamp. */
    created_at: string;
};

/** Case-rollup item — the read-only aggregate carries which treatment each file belongs to. */
export type CaseMediaItem = MediaItem & {
    treatment_id: number;
    /** ISO 8601 UTC timestamp of the owning treatment's completion, or null. */
    treatment_date: string | null;
};
