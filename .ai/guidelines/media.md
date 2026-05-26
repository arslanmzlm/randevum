# Media handling

- Use Spatie Medialibrary with the `spatie/image` driver and S3 storage from MVP onward. Store clinic images as Medialibrary collections (`logo`/`cover`) — no image-path columns on tables.
- On upload, generate exactly 3 conversions, queued on the `media` queue (`->queued()`): `large` (1920w), `medium` (800w), `thumb` (300w).
- Conversion format is WebP at quality 80–85. Always keep the original file.
- Enforce file access control scoped by tenant + clinic; the stored file path carries a tenant + clinic prefix. All media access goes through the Media module wrapper.
- Deletion and retention rules for media live in the deletion-retention guidelines.
