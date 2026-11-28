<?php

// Turkce servis mesajlari. Makine tarafindan okunan sabit tanimlayicilar
// cevrilmez; yalnizca insana yonelik metinler burada bulunur.
return [
    'auth' => [
        'invalid_credentials' => 'Geçersiz kimlik bilgileri.',
        'authentication_required' => 'Kimlik doğrulaması gerekli.',
        'invalid_refresh_token' => 'Yenileme anahtarı geçersiz veya süresi dolmuş.',
    ],
    'sessions' => [
        'revoked' => 'Oturum iptal edildi.',
        'revoked_all' => 'Diğer oturumlar iptal edildi.',
    ],
    'credentials' => [
        'current_password_invalid' => 'Mevcut şifre hatalı.',
        'password_changed' => 'Şifre değiştirildi. Diğer oturumlar iptal edildi.',
        'recovery_requested' => 'Hesap mevcutsa kurtarma talimatları gönderildi.',
        'recovery_completed' => 'Şifre sıfırlama tamamlandı. Tüm oturumlar iptal edildi.',
        'challenge_invalid' => 'Anahtar geçersiz, süresi dolmuş veya zaten kullanılmış.',
        'account_setup_completed' => 'Hesap etkinleştirildi. Şimdi giriş yapabilirsiniz.',
        'verification_requested' => 'Doğrulanmamış e-posta adresi varsa doğrulama talimatları gönderildi.',
        'verification_completed' => 'E-posta adresi doğrulandı.',
        'policy' => [
            'too_short' => 'Şifre en az :min karakter olmalıdır.',
            'too_long' => 'Şifre :max karakteri geçemez.',
            'blocklisted' => 'Bu şifre yaygın olarak kullanılıyor ve kabul edilmez.',
            'identifier_similar' => 'Şifre giriş tanımlayıcınızı veya görünen adınızı içeremez.',
        ],
    ],
    'profile' => [
        'updated' => 'Profil güncellendi.',
    ],
    'admin' => [
        'identifier_conflict' => 'Bu giriş tanımlayıcısı zaten kullanılıyor.',
    ],
    'oauth' => [
        'token_revoked' => 'Anahtar iptal edildi.',
    ],
    'delivery' => [
        'password_reset' => [
            'subject' => 'uLaxys şifre sıfırlama',
            'body' => 'Şifrenizi sıfırlamak için bu tek kullanımlık anahtarı geçerlilik süresi içinde kullanın: :token',
        ],
        'email_verification' => [
            'subject' => 'uLaxys e-posta doğrulama',
            'body' => 'E-posta adresinizi doğrulamak için bu tek kullanımlık anahtarı kullanın: :token',
        ],
        'account_setup' => [
            'subject' => 'uLaxys hesap etkinleştirme',
            'body' => 'Hesabınızı etkinleştirmek ve şifre seçmek için bu tek kullanımlık anahtarı kullanın: :token',
        ],
    ],
];
