<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store as registerStore } from '@/routes/register';

defineOptions({ layout: AuthLayout });

const { t } = useI18n();

interface LegalDoc {
    type: string;
    title: string;
    version: string;
    content: string;
}

const props = defineProps<{
    verticals: Array<{ id: number; slug: string; name: string }>;
    legalDocuments: Record<string, LegalDoc>;
    status: string | null;
}>();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    vertical_id: props.verticals[0]?.id ?? null,
    clinic_name: '',
    password: '',
    password_confirmation: '',
    terms: false,
    dpa: false,
});

const openDoc = ref<LegalDoc | null>(null);

function showDoc(type: string): void {
    openDoc.value = props.legalDocuments[type] ?? null;
}

function submit(): void {
    form.post(registerStore().url, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div>
        <Head :title="t('auth.register.title')" />

        <Message
            v-if="status"
            severity="success"
            :closable="false"
            class="mb-6"
        >
            {{ t(`auth.status.${status}`, status) }}
        </Message>

        <h1 class="text-2xl font-semibold text-surface-900">
            {{ t('auth.register.title') }}
        </h1>
        <p class="mt-2 mb-8 text-sm text-surface-600">
            {{ t('auth.register.subtitle') }}
        </p>

        <form novalidate @submit.prevent="submit">
            <div class="flex flex-col gap-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField
                        :label="t('auth.register.first_name')"
                        :error="form.errors.first_name"
                    >
                        <InputText
                            v-model="form.first_name"
                            autocomplete="given-name"
                            fluid
                        />
                    </FormField>

                    <FormField
                        :label="t('auth.register.last_name')"
                        :error="form.errors.last_name"
                    >
                        <InputText
                            v-model="form.last_name"
                            autocomplete="family-name"
                            fluid
                        />
                    </FormField>
                </div>

                <FormField
                    :label="t('auth.register.email')"
                    :error="form.errors.email"
                >
                    <InputText
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('auth.register.vertical')"
                    :error="form.errors.vertical_id"
                >
                    <Select
                        v-model="form.vertical_id"
                        :options="verticals"
                        option-label="name"
                        option-value="id"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('auth.register.clinic_name')"
                    :error="form.errors.clinic_name"
                >
                    <InputText
                        v-model="form.clinic_name"
                        autocomplete="organization"
                        fluid
                    />
                </FormField>

                <FormField
                    :label="t('auth.register.password')"
                    :error="form.errors.password"
                >
                    <Password
                        v-model="form.password"
                        autocomplete="new-password"
                        :feedback="true"
                        toggle-mask
                        fluid
                    >
                        <template #maskicon="{ toggleCallback }">
                            <IconEye
                                class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                @click="toggleCallback"
                            />
                        </template>
                        <template #unmaskicon="{ toggleCallback }">
                            <IconEyeOff
                                class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                @click="toggleCallback"
                            />
                        </template>
                    </Password>
                </FormField>

                <FormField
                    :label="t('auth.register.password_confirmation')"
                    :error="form.errors.password_confirmation"
                >
                    <Password
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        :feedback="false"
                        toggle-mask
                        fluid
                    >
                        <template #maskicon="{ toggleCallback }">
                            <IconEye
                                class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                @click="toggleCallback"
                            />
                        </template>
                        <template #unmaskicon="{ toggleCallback }">
                            <IconEyeOff
                                class="absolute top-1/2 right-3 size-5 -translate-y-1/2 cursor-pointer text-surface-500"
                                @click="toggleCallback"
                            />
                        </template>
                    </Password>
                </FormField>

                <div class="flex flex-col gap-1.5">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            v-model="form.terms"
                            input-id="terms"
                            :binary="true"
                            :invalid="!!form.errors.terms"
                        />
                        <label
                            for="terms"
                            class="cursor-pointer text-sm text-surface-600 select-none"
                        >
                            <i18n-t
                                keypath="auth.register.terms_agree"
                                tag="span"
                            >
                                <template #terms>
                                    <button
                                        type="button"
                                        class="font-medium text-brand hover:text-brand-dark"
                                        @click="showDoc('terms_of_service')"
                                    >
                                        {{ t('auth.register.terms_label') }}
                                    </button>
                                </template>
                                <template #privacy>
                                    <button
                                        type="button"
                                        class="font-medium text-brand hover:text-brand-dark"
                                        @click="showDoc('privacy_policy')"
                                    >
                                        {{ t('auth.register.privacy_label') }}
                                    </button>
                                </template>
                            </i18n-t>
                        </label>
                    </div>
                    <small
                        v-if="form.errors.terms"
                        class="text-xs text-red-500"
                    >
                        {{ form.errors.terms }}
                    </small>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div class="flex items-start gap-2">
                        <Checkbox
                            v-model="form.dpa"
                            input-id="dpa"
                            :binary="true"
                            :invalid="!!form.errors.dpa"
                        />
                        <label
                            for="dpa"
                            class="cursor-pointer text-sm text-surface-600 select-none"
                        >
                            <i18n-t
                                keypath="auth.register.dpa_agree"
                                tag="span"
                            >
                                <template #dpa>
                                    <button
                                        type="button"
                                        class="font-medium text-brand hover:text-brand-dark"
                                        @click="
                                            showDoc('data_processing_agreement')
                                        "
                                    >
                                        {{ t('auth.register.dpa_label') }}
                                    </button>
                                </template>
                            </i18n-t>
                        </label>
                    </div>
                    <small v-if="form.errors.dpa" class="text-xs text-red-500">
                        {{ form.errors.dpa }}
                    </small>
                </div>

                <Button
                    type="submit"
                    :label="t('auth.register.submit')"
                    :loading="form.processing"
                    class="w-full"
                    size="large"
                />
            </div>
        </form>

        <div class="mt-8 border-t border-surface-200 pt-6">
            <p class="text-center text-sm text-surface-600">
                {{ t('auth.register.have_account') }}
                <Link
                    :href="login().url"
                    class="ml-1 font-semibold text-brand transition-colors hover:text-brand-dark"
                >
                    {{ t('auth.register.login_link') }}
                </Link>
            </p>
        </div>

        <Dialog
            :visible="openDoc !== null"
            modal
            dismissable-mask
            :header="openDoc?.title"
            :style="{ width: '40rem', maxWidth: '90vw' }"
            @update:visible="openDoc = null"
        >
            <p v-if="openDoc" class="mb-3 text-xs text-surface-500">
                {{
                    t('auth.register.doc_version', { version: openDoc.version })
                }}
            </p>
            <p
                class="text-sm leading-relaxed whitespace-pre-line text-surface-700"
            >
                {{ openDoc?.content }}
            </p>
            <template #footer>
                <Button
                    :label="t('auth.register.doc_close')"
                    text
                    @click="openDoc = null"
                />
            </template>
        </Dialog>
    </div>
</template>
