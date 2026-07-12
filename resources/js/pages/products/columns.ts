/** Static column config for the products list — order, field keys, sortable flags,
 * header labels and widths. Cell rendering (formatters, handlers) stays in the page,
 * so the body markup keeps its component scope. */
export type ProductColumn = {
    /** Stable key + the branch discriminator used by the page's #body slot. */
    key: string;
    field?: string;
    header: string;
    sortable?: boolean;
    class?: string;
    /** Only rendered when the user can manage products. */
    requiresManage?: boolean;
};

export function productColumns(t: (key: string) => string): ProductColumn[] {
    return [
        {
            key: 'name',
            field: 'name',
            header: t('product.columns.name'),
            sortable: true,
        },
        {
            key: 'price',
            field: 'price',
            header: t('product.columns.price'),
            sortable: true,
            class: 'w-40',
        },
        {
            key: 'current_stock',
            field: 'current_stock',
            header: t('product.columns.stock'),
            sortable: true,
            class: 'w-44',
        },
        {
            key: 'is_active',
            field: 'is_active',
            header: t('product.columns.status'),
            sortable: true,
            class: 'w-32',
        },
        {
            key: 'actions',
            header: t('product.columns.actions'),
            class: 'w-32',
            requiresManage: true,
        },
    ];
}
