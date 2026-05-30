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
                width: {
                    expand: 'Tam genişlik',
                    collapse: 'Ortalı görünüm',
                },
            },
            nav: {
                dashboard: 'Ana sayfa',
                clinic: 'Klinik profili',
                account: 'Hesabım',
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
            account: {
                title: 'Hesabım',
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
            clinic: {
                title: 'Klinik Profili',
                subtitle:
                    'Kliniğinizin bilgilerini ve çalışma saatlerini yönetin.',
                save: 'Kaydet',
                sections: {
                    info: 'Klinik Bilgileri',
                    contact: 'İletişim',
                    address: 'Adres',
                    hours: 'Çalışma Saatleri',
                    media: 'Görseller',
                },
                fields: {
                    name: 'Klinik adı',
                    slug: 'Kısa ad (slug)',
                    description: 'Açıklama',
                    vertical: 'Klinik türü',
                    phone: 'Telefon',
                    email: 'E-posta adresi',
                    website: 'Web sitesi',
                    country: 'Ülke',
                    city: 'Şehir',
                    district: 'İlçe',
                    address: 'Açık adres',
                    postal_code: 'Posta kodu',
                    slot_duration: 'Varsayılan randevu süresi',
                },
                hints: {
                    slug: 'Yalnızca küçük harf, rakam ve tire kullanılabilir.',
                    slot_duration:
                        'Randevu takviminde kullanılacak varsayılan slot süresi (dakika).',
                },
                days: {
                    monday: 'Pazartesi',
                    tuesday: 'Salı',
                    wednesday: 'Çarşamba',
                    thursday: 'Perşembe',
                    friday: 'Cuma',
                    saturday: 'Cumartesi',
                    sunday: 'Pazar',
                },
                hours: {
                    open: 'Açılış',
                    close: 'Kapanış',
                    closed: 'Kapalı',
                    add_break: 'Mola ekle',
                    remove_break: 'Molayı kaldır',
                    break: 'Mola',
                    break_start: 'Mola başlangıcı',
                    break_end: 'Mola bitişi',
                    copy_to_all: 'Saatleri eşleştir',
                    copy_to_all_hint:
                        'Pazartesi günü ayarlarını tüm günlere uygular.',
                },
                verticals: {
                    podiatry: 'Podoloji',
                },
                media: {
                    logo: 'Logo',
                    cover: 'Kapak görseli (web)',
                    cover_mobile: 'Kapak görseli (mobil)',
                    logo_hint: 'Kare görsel, en az 128×128 piksel.',
                    cover_hint: '16:9 görsel, en az 1920×1080 piksel.',
                    cover_mobile_hint: 'Kare görsel, en az 1440×1440 piksel.',
                    upload: 'Görsel yükle',
                    remove: 'Kaldır',
                    remove_confirm:
                        'Bu görseli kaldırmak istediğinize emin misiniz?',
                    empty: 'Görsel yok',
                    uploading: 'Yükleniyor…',
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
                confirm_title: 'Emin misiniz?',
                delete: 'Sil',
                cancel: 'Vazgeç',
            },
        },
    },
});
