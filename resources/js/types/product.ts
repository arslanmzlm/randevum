import type { Paginated, TableState } from '@/types/table';

/** Canonical product shape emitted by ProductResource (index + edit). */
export type Product = {
    id: number;
    name: string;
    description: string | null;
    brand: string | null;
    category: string | null;
    sku: string | null;
    unit: string;
    /** decimal:2 serialized as a string, e.g. "150.00". */
    price: string;
    /** May be negative — stock is allowed to go below zero (no min threshold). */
    current_stock: number;
    is_active: boolean;
};

/** Server-side list JSON:API state echoed back by the controller. */
export type ProductQuery = TableState<{
    is_active: boolean | null;
}>;

export type ProductIndexProps = {
    products: Paginated<Product>;
    query: ProductQuery;
    canManage: boolean;
    /** ISO 4217 code of the active clinic, for price formatting. */
    currency: string;
};

/** Editable catalog fields shared by the create and edit forms (stock is separate). */
export type ProductFormData = {
    name: string;
    description: string;
    brand: string;
    category: string;
    sku: string;
    unit: string;
    /** null on a fresh create form so the currency input renders empty, not ₺0,00. */
    price: number | null;
    is_active: boolean;
};

/** Create form = catalog fields plus an optional initial stock level. */
export type ProductCreateFormData = ProductFormData & {
    /** May be negative; null leaves the column at its default (0). */
    current_stock: number | null;
};

/** Standalone stock-adjust form (PATCH products.stock.update). */
export type ProductStockFormData = {
    /** May be negative. */
    current_stock: number;
};

/** Existing brand/category values for this clinic, feeding the form autocompletes. */
export type ProductSuggestions = {
    brands: string[];
    categories: string[];
};

export type ProductCreateProps = ProductSuggestions & {
    currency: string;
};

export type ProductEditProps = ProductSuggestions & {
    product: Product;
    currency: string;
};
