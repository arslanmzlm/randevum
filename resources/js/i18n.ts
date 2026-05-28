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
            app: {
                greeting: 'Hoşgeldiniz, {name}',
            },
            nav: {
                dashboard: 'Ana sayfa',
                settings: 'Ayarlar',
            },
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
                register: {
                    title: 'Hesap Oluştur',
                    subtitle: 'Kliniğinizi birkaç adımda kayıt edin.',
                    first_name: 'Ad',
                    last_name: 'Soyad',
                    email: 'E-posta adresi',
                    password: 'Şifre',
                    password_confirmation: 'Şifre tekrarı',
                    vertical: 'Klinik türü',
                    clinic_name: 'Klinik adı',
                    terms_label: 'Kullanım koşulları ve gizlilik politikası',
                    terms_agree: '{terms} metnini okudum ve kabul ediyorum.',
                    submit: 'Hesap Oluştur',
                    have_account: 'Zaten hesabınız var mı?',
                    login_link: 'Giriş yapın',
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
            settings: {
                title: 'Ayarlar',
                profile: {
                    heading: 'Profil Bilgileri',
                    description: 'Ad ve soyadınızı güncelleyin.',
                    first_name: 'Ad',
                    last_name: 'Soyad',
                    submit: 'Kaydet',
                },
                password: {
                    heading: 'Şifre Değiştir',
                    description:
                        'Hesabınızın güvenliği için güçlü bir şifre kullanın.',
                    current: 'Mevcut şifre',
                    new: 'Yeni şifre',
                    confirmation: 'Yeni şifre tekrarı',
                    submit: 'Şifreyi Güncelle',
                },
            },
            admin: {
                title: 'Admin Paneli',
                welcome: 'Hoş geldiniz,',
                placeholder:
                    'Superadmin / Admin / Moderatör paneli — ilerleyen fazlarda geliştirilecek.',
            },
            common: {
                form_error: 'Girdiğiniz bilgileri kontrol edin.',
                too_many_requests:
                    'Çok fazla deneme yaptınız. Lütfen biraz bekleyip tekrar deneyin.',
                session_expired:
                    'Oturumunuz sona erdi. Lütfen sayfayı yenileyip tekrar deneyin.',
            },
        },
    },
});
