import { createI18n } from 'vue-i18n';

/**
 * Frontend i18n. Mirrors the backend lang files; Turkish is the MVP default,
 * but locale/fallback are never hardcoded assumptions — they track the
 * clinic's configured locale once that is wired through page props.
 */
export const i18n = createI18n({
    legacy: false,
    locale: 'tr',
    fallbackLocale: 'tr',
    messages: {
        tr: {
            auth: {
                layout: {
                    brand: 'Randevum',
                    tagline: 'Klinik yönetim platformu',
                },
                login: {
                    title: 'Giriş Yap',
                    subtitle: 'Hesabınıza giriş yapın',
                    email: 'E-posta adresi',
                    password: 'Şifre',
                    remember: 'Beni hatırla',
                    submit: 'Giriş Yap',
                    forgot_password: 'Şifremi unuttum',
                    no_account: 'Hesabınız yok mu?',
                    register: 'Kayıt ol',
                },
                otp: {
                    phone: 'Telefon numarası',
                    request: 'SMS Gönder',
                    code: 'Doğrulama kodu',
                    verify: 'Doğrula',
                    sent: 'Doğrulama kodu telefon numaranıza gönderildi.',
                    invalid_code:
                        'Girdiğiniz kod hatalı, süresi dolmuş veya çok fazla deneme yapıldı.',
                },
                logout: 'Çıkış Yap',
                status: {
                    'otp-sent': 'Doğrulama kodu telefon numaranıza gönderildi.',
                    'passwords.sent':
                        'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.',
                },
            },
            dashboard: {
                title: 'Dashboard',
                welcome: 'Hoş geldiniz,',
                placeholder: 'Panel içeriği yakında eklenecek.',
            },
            admin: {
                title: 'Admin Paneli',
                welcome: 'Hoş geldiniz,',
                placeholder:
                    'Superadmin / Admin / Moderatör paneli — ilerleyen fazlarda geliştirilecek.',
            },
        },
    },
});
