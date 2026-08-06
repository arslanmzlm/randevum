import ProductFields from '@/components/products/ProductFields.vue';
import { store, update } from '@/routes/products';
import type { CrudResource } from '@/types/crud';
import type { Product, ProductCreateFormData } from '@/types/product';

export const productResource: CrudResource<ProductCreateFormData, Product> = {
    lang: 'product',
    width: 'w-full max-w-2xl',
    store,
    update,
    empty: () => ({
        name: '',
        description: '',
        brand: '',
        category: '',
        sku: '',
        unit: 'adet',
        price: null,
        current_stock: null,
        is_active: true,
    }),
    // current_stock stays null on edit: the field is hidden and UpdateProductRequest doesn't
    // validate it, so stock only ever changes through the stock adjustment.
    toForm: (product) => ({
        name: product.name,
        description: product.description ?? '',
        brand: product.brand ?? '',
        category: product.category ?? '',
        sku: product.sku ?? '',
        unit: product.unit,
        price: Number(product.price),
        current_stock: null,
        is_active: product.is_active,
    }),
    fields: ProductFields,
};
