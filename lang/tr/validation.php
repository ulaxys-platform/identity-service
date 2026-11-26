<?php

// Yerellestirilmis dogrulama mesajlari: Identity V2 kurallari tarafindan
// kullanilan alt kume. Ingilizce varsayilanlar framework tarafindan saglanir.
return [
    'required' => ':attribute zorunludur.',
    'string' => ':attribute metin olmalıdır.',
    'integer' => ':attribute tam sayı olmalıdır.',
    'max' => [
        'string' => ':attribute en fazla :max karakter olabilir.',
        'integer' => ':attribute en fazla :max olabilir.',
    ],
    'min' => [
        'string' => ':attribute en az :min karakter olmalıdır.',
        'integer' => ':attribute en az :min olmalıdır.',
    ],
    'in' => 'Seçilen :attribute geçersiz.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'timezone' => ':attribute geçerli bir zaman dilimi olmalıdır.',
    'regex' => ':attribute formatı geçersiz.',
];
