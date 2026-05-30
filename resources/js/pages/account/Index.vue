<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { update as updatePassword } from '@/routes/user-password';
import { update as updateProfileInformation } from '@/routes/user-profile-information';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const page = usePage();
const user = page.props.auth?.user;

const profileForm = useForm({
    first_name: user?.first_name ?? '',
    last_name: user?.last_name ?? '',
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitProfile(): void {
    profileForm.put(updateProfileInformation().url, {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
    });
}

function submitPassword(): void {
    passwordForm.put(updatePassword().url, {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('account.title')" />

        <PageHeader
            :title="t('account.title')"
            :breadcrumbs="[{ label: t('nav.account') }]"
        />

        <section
            class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="mb-6">
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('account.profile.heading') }}
                </h2>
                <p class="mt-1 text-sm text-surface-500">
                    {{ t('account.profile.description') }}
                </p>
            </header>

            <form novalidate @submit.prevent="submitProfile">
                <div class="flex flex-col gap-5">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField
                            :label="t('account.profile.first_name')"
                            :error="profileForm.errors.first_name"
                        >
                            <InputText
                                v-model="profileForm.first_name"
                                autocomplete="given-name"
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('account.profile.last_name')"
                            :error="profileForm.errors.last_name"
                        >
                            <InputText
                                v-model="profileForm.last_name"
                                autocomplete="family-name"
                                fluid
                            />
                        </FormField>
                    </div>

                    <Button
                        type="submit"
                        :label="t('account.profile.submit')"
                        :loading="profileForm.processing"
                        class="self-start"
                    />
                </div>
            </form>
        </section>

        <section
            class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
        >
            <header class="mb-6">
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('account.password.heading') }}
                </h2>
                <p class="mt-1 text-sm text-surface-500">
                    {{ t('account.password.description') }}
                </p>
            </header>

            <form novalidate @submit.prevent="submitPassword">
                <div class="flex flex-col gap-5">
                    <FormField
                        :label="t('account.password.current')"
                        :error="passwordForm.errors.current_password"
                    >
                        <Password
                            v-model="passwordForm.current_password"
                            autocomplete="current-password"
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

                    <FormField
                        :label="t('account.password.new')"
                        :error="passwordForm.errors.password"
                    >
                        <Password
                            v-model="passwordForm.password"
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
                        :label="t('account.password.confirmation')"
                        :error="passwordForm.errors.password_confirmation"
                    >
                        <Password
                            v-model="passwordForm.password_confirmation"
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

                    <Button
                        type="submit"
                        :label="t('account.password.submit')"
                        :loading="passwordForm.processing"
                        class="self-start"
                    />
                </div>
            </form>
        </section>
    </div>
</template>
