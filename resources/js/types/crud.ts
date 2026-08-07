import type { Component } from 'vue';

/** Wayfinder route helper result — only the url is used. */
type RouteUrl = { url: string };

/**
 * Field value types a dialog form can hold. The dialog erases its form to this shape because one
 * component serves every entity; each field partial names its own form type through `useCrudForm`.
 */
export type CrudFormValues = Record<
    string,
    string | number | boolean | Date | File | null | undefined
>;

/**
 * Everything the shared CRUD dialog needs to run one entity's create/edit form. The frame (dialog,
 * form lifecycle, submit routing, labels) is shared; `fields` stays hand-written per entity so
 * conditional or dependent inputs never have to fit a schema.
 */
export type CrudResource<TForm extends object, TItem extends { id: number }> = {
    /** Lang key prefix — `<lang>.create_title`, `.edit_title`, `.create_submit`, `.save`. */
    lang: string;
    /** Dialog width utility classes; defaults to a single-column form width. */
    width?: string;
    store: () => RouteUrl;
    /** Omitted for an immutable resource (no edit route) — the dialog then only ever creates. */
    update?: (id: number) => RouteUrl;
    /** Blank form for create. */
    empty: () => TForm;
    /** Form seeded from the row being edited; omitted alongside `update`. */
    toForm?: (item: TItem) => TForm;
    /** Payload shaping before submit (dates → strings, empty → null). */
    transform?: (data: TForm) => Record<string, unknown>;
    /** The entity's own field markup; reads the form through `useCrudForm()`. */
    fields: Component;
};
