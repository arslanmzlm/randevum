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
                doctors: 'Doktorlar',
                patients: 'Hastalar',
                services: 'Hizmetler',
                products: 'Ürünler',
                account: 'Hesabım',
                profile_mine: 'Profilim',
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
                    remove_confirm:
                        'Bu görseli kaldırmak istediğinize emin misiniz?',
                },
            },
            doctor: {
                title: 'Doktorlar',
                subtitle: 'Kliniğinizin doktorlarını yönetin.',
                add: 'Doktor Ekle',
                create_own: 'Beni de doktor yap',
                remove: 'Kaldır',
                remove_confirm:
                    '{name} adlı doktoru kaldırmak istediğinize emin misiniz?',
                empty: 'Henüz doktor eklenmemiş.',
                empty_filtered: 'Aramayla eşleşen doktor yok.',
                search_placeholder: 'Doktor ara...',
                filter_status: 'Durum',
                active: 'Aktif',
                passive: 'Pasif',
                edit: 'Düzenle',
                back: 'Geri',
                create_title: 'Doktor Ekle',
                create_subtitle: 'Yeni bir doktor hesabı ve profili oluşturun.',
                create_submit: 'Doktoru Ekle',
                edit_title: 'Doktor Profili',
                edit_subtitle: 'Doktor bilgilerini güncelleyin.',
                save: 'Kaydet',
                sections: {
                    account: 'Hesap Bilgileri',
                    info: 'Profil Bilgileri',
                    about: 'Hakkında ve Sertifikalar',
                    avatar: 'Profil Fotoğrafı',
                },
                fields: {
                    name: 'Ad Soyad',
                    first_name: 'Ad',
                    last_name: 'Soyad',
                    email: 'E-posta adresi',
                    password: 'Geçici şifre',
                    password_confirmation: 'Şifre tekrarı',
                    title: 'Ünvan',
                    specialization: 'Uzmanlık',
                    bio: 'Hakkında',
                    license_number: 'Diploma / Lisans no',
                    certificate: 'Sertifikalar',
                    is_active: 'Aktif',
                    avatar: 'Profil fotoğrafı',
                },
                hints: {
                    is_active: 'Pasif doktorlar randevu takviminde görünmez.',
                    password: 'Doktor ilk girişte bu şifreyi değiştirebilir.',
                    email_readonly:
                        'Giriş e-postası kayıt sonrası değiştirilemez.',
                    title: 'Tam adın önüne eklenir — örn. "Prof. Dr."',
                },
                avatar: {
                    hint: 'Kare görsel, en az 256×256 piksel.',
                    remove_confirm:
                        'Profil fotoğrafını kaldırmak istediğinize emin misiniz?',
                },
            },
            service: {
                title: 'Hizmetler',
                subtitle: 'Kliniğinizin hizmet kataloğunu yönetin.',
                add: 'Hizmet Ekle',
                edit: 'Düzenle',
                remove: 'Kaldır',
                remove_confirm:
                    '{name} adlı hizmeti kaldırmak istediğinize emin misiniz?',
                active: 'Aktif',
                passive: 'Pasif',
                empty: 'Henüz hizmet eklenmemiş.',
                empty_filtered: 'Aramayla eşleşen hizmet yok.',
                search_placeholder: 'Hizmet ara...',
                filter_status: 'Durum',
                back: 'Geri',
                create_title: 'Hizmet Ekle',
                create_subtitle: 'Kataloğa yeni bir hizmet ekleyin.',
                create_submit: 'Hizmeti Ekle',
                edit_title: 'Hizmeti Düzenle',
                edit_subtitle: 'Hizmet bilgilerini güncelleyin.',
                save: 'Kaydet',
                columns: {
                    name: 'Hizmet',
                    price: 'Ücret',
                    status: 'Durum',
                    actions: 'İşlemler',
                },
                sections: {
                    info: 'Hizmet Bilgileri',
                    templates: 'Varsayılan Klinik Metinleri',
                },
                fields: {
                    name: 'Hizmet adı',
                    description: 'Açıklama',
                    price: 'Ücret',
                    is_active: 'Aktif',
                    default_complaint: 'Şikayet (varsayılan)',
                    default_diagnosis: 'Tanı (varsayılan)',
                    default_treatment_process: 'Tedavi süreci (varsayılan)',
                },
                hints: {
                    is_active:
                        'Pasif hizmetler tedavi ekranındaki listede görünmez.',
                    templates:
                        'Bu metinler tedavi eklerken ilgili alanlara otomatik gelir.',
                },
            },
            product: {
                title: 'Ürünler',
                subtitle: 'Kliniğinizin ürün ve stok kataloğunu yönetin.',
                add: 'Ürün Ekle',
                edit: 'Düzenle',
                remove: 'Kaldır',
                remove_confirm:
                    '{name} adlı ürünü kaldırmak istediğinize emin misiniz?',
                active: 'Aktif',
                passive: 'Pasif',
                empty: 'Henüz ürün eklenmemiş.',
                empty_filtered: 'Aramayla eşleşen ürün yok.',
                search_placeholder: 'Ürün ara...',
                filter_status: 'Durum',
                update_stock: 'Stok Güncelle',
                back: 'Geri',
                create_title: 'Ürün Ekle',
                create_subtitle: 'Kataloğa yeni bir ürün ekleyin.',
                create_submit: 'Ürünü Ekle',
                edit_title: 'Ürünü Düzenle',
                edit_subtitle: 'Ürün bilgilerini güncelleyin.',
                save: 'Kaydet',
                stock_save: 'Stoğu Güncelle',
                columns: {
                    name: 'Ürün',
                    price: 'Fiyat',
                    stock: 'Stok',
                    status: 'Durum',
                    actions: 'İşlemler',
                },
                sections: {
                    info: 'Ürün Bilgileri',
                    stock: 'Stok',
                },
                fields: {
                    name: 'Ürün adı',
                    description: 'Açıklama',
                    brand: 'Marka',
                    category: 'Kategori',
                    sku: 'Stok kodu (SKU)',
                    unit: 'Birim',
                    price: 'Fiyat',
                    current_stock: 'Stok adedi',
                    is_active: 'Aktif',
                },
                hints: {
                    is_active:
                        'Pasif ürünler tedavi ekranındaki listede görünmez.',
                    current_stock:
                        'Stok negatife düşebilir; başlangıç değeri boş bırakılırsa 0 kabul edilir.',
                    stock: 'Stok adedini hızlıca güncelleyin. Negatif değer girilebilir.',
                },
            },
            patient: {
                title: 'Hastalar',
                subtitle: 'Kliniğinizin hasta kayıtlarını yönetin.',
                add: 'Hasta Ekle',
                edit: 'Düzenle',
                remove: 'Kaldır',
                view: 'Görüntüle',
                remove_confirm:
                    '{name} adlı hastayı kaldırmak istediğinize emin misiniz?',
                empty: 'Henüz hasta eklenmemiş.',
                empty_filtered: 'Aramayla eşleşen hasta yok.',
                search_placeholder: 'Ad, soyad veya telefon ara...',
                quick_find_placeholder: 'Hasta ara (ad veya telefon)…',
                quick_find_no_results: 'Eşleşen hasta yok',
                quick_find_min_chars:
                    'Aramak için en az {count} karakter yazın',
                filter_gender: 'Cinsiyet',
                filter_legacy: 'Kayıt türü',
                legacy_badge: 'Sistem öncesi',
                legacy_only: 'Sistem öncesi',
                not_legacy: 'Sistemde oluşturulan',
                back: 'Geri',
                save: 'Kaydet',
                create_title: 'Hasta Ekle',
                create_subtitle: 'Yeni bir hasta kaydı oluşturun.',
                create_submit: 'Hastayı Ekle',
                edit_title: 'Hastayı Düzenle',
                edit_subtitle: 'Hasta bilgilerini güncelleyin.',
                detail_subtitle: 'Hasta kaydı ve geçmişi.',
                columns: {
                    name: 'Hasta',
                    phone: 'Telefon',
                    age: 'Yaş',
                    gender: 'Cinsiyet',
                    actions: 'İşlemler',
                },
                sections: {
                    info: 'Kişisel Bilgiler',
                    contact: 'İletişim',
                    preferences: 'Tercihler',
                    notes: 'Klinik Notu',
                    profile: 'Hasta Bilgileri',
                    treatments: 'Tedavi Geçmişi',
                },
                fields: {
                    first_name: 'Ad',
                    last_name: 'Soyad',
                    phone: 'Telefon',
                    contact_phone: 'İkincil telefon',
                    email: 'E-posta adresi',
                    birth_date: 'Doğum tarihi',
                    gender: 'Cinsiyet',
                    notification_enabled: 'Bildirimler',
                    is_legacy: 'Sistem öncesi hasta',
                    notes: 'Not',
                },
                hints: {
                    notification_enabled:
                        'Kapalıysa hastaya hatırlatma ve bilgilendirme SMS’i gönderilmez.',
                    is_legacy:
                        'Sistemden önce kaydedilmiş (geçmiş/aktarılmış) hastaları işaretler.',
                    contact_phone:
                        'Hastaya ulaşılamadığında aranacak ikinci numara (isteğe bağlı).',
                },
                gender: {
                    male: 'Erkek',
                    female: 'Kadın',
                    other: 'Diğer',
                },
                age_value: '{age} yaş',
                notifications_on: 'Açık',
                notifications_off: 'Kapalı',
                not_specified: 'Belirtilmemiş',
                no_notes: 'Bu hasta için not eklenmemiş.',
                no_treatments: 'Bu hasta için henüz tedavi kaydı yok.',
                no_treatments_hint:
                    'Tedaviler eklendiğinde burada listelenecek.',
                restore: {
                    title: 'Silinmiş hasta bulundu',
                    message:
                        '{name} bu numarayla daha önce kaydedilmiş ve silinmiş. Kaydı geri yüklemek ister misiniz?',
                    accept: 'Geri yükle',
                    reject: 'Vazgeç',
                },
            },
            password_reminder: {
                title: 'Şifrenizi güncelleyin',
                message:
                    'Hesabınız geçici bir şifre ile oluşturuldu. Güvenliğiniz için şifrenizi değiştirmenizi öneririz.',
                change: 'Şifremi değiştir',
                later: 'Daha sonra',
            },
            error: {
                back_home: "Dashboard'a dön",
                default: {
                    title: 'Bir hata oluştu',
                    message: 'Beklenmeyen bir hata oluştu.',
                },
                403: {
                    title: 'Erişim reddedildi',
                    message: 'Bu sayfayı görüntüleme yetkiniz yok.',
                },
                404: {
                    title: 'Sayfa bulunamadı',
                    message:
                        'Aradığınız sayfa mevcut değil veya taşınmış olabilir.',
                },
                419: {
                    title: 'Oturum süresi doldu',
                    message:
                        'Sayfa zaman aşımına uğradı. Lütfen tekrar deneyin.',
                },
                500: {
                    title: 'Sunucu hatası',
                    message:
                        'Beklenmeyen bir hata oluştu. Lütfen daha sonra tekrar deneyin.',
                },
                503: {
                    title: 'Servis kullanılamıyor',
                    message:
                        'Sistem şu anda bakımda. Lütfen daha sonra tekrar deneyin.',
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
                media: {
                    empty: 'Görsel yok',
                    uploading: 'Yükleniyor…',
                    upload: 'Görsel yükle',
                    remove: 'Kaldır',
                },
            },
        },
    },
});
