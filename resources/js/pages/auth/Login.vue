<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store as loginStore } from '@/routes/login';
import { request as passwordRequest } from '@/routes/password';

defineOptions({ layout: AuthLayout });

const { t } = useI18n();

const props = defineProps<{
    canResetPassword: boolean;
    canLoginWithOtp: boolean;
    status: string | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.post(loginStore().url, {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <div>
        <Head :title="t('auth.login.title')" />

        <Message
            v-if="props.status"
            severity="success"
            :closable="false"
            class="mb-6"
        >
            {{ t(`auth.status.${props.status}`, props.status) }}
        </Message>

        <h1 class="mb-8 text-2xl font-semibold text-surface-900">
            {{ t('auth.login.title') }}
        </h1>

        <form novalidate @submit.prevent="submit">
            <div class="flex flex-col gap-5">
                <FormField
                    :label="t('auth.login.email')"
                    :error="form.errors.email"
                >
                    <InputText
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        fluid
                    />
                </FormField>

                <div class="flex flex-col gap-1.5">
                    <FormField
                        :label="t('auth.login.password')"
                        :error="form.errors.password"
                    >
                        <Password
                            v-model="form.password"
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
                    <Link
                        v-if="props.canResetPassword"
                        :href="passwordRequest().url"
                        class="self-end text-xs font-medium text-brand transition-colors hover:text-brand-dark"
                    >
                        {{ t('auth.login.forgot_password') }}
                    </Link>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox
                        v-model="form.remember"
                        input-id="remember"
                        :binary="true"
                    />
                    <label
                        for="remember"
                        class="cursor-pointer text-sm text-surface-600 select-none"
                    >
                        {{ t('auth.login.remember') }}
                    </label>
                </div>

                <Button
                    type="submit"
                    :label="t('auth.login.submit')"
                    :loading="form.processing"
                    class="w-full"
                    size="large"
                />
            </div>
        </form>

        <div class="mt-8 border-t border-surface-200 pt-6">
            <p class="text-center text-sm text-surface-600">
                {{ t('auth.login.no_account') }}
                <Link
                    :href="register().url"
                    class="ml-1 font-semibold text-brand transition-colors hover:text-brand-dark"
                >
                    {{ t('auth.login.register') }}
                </Link>
            </p>
        </div>
    </div>
</template>
