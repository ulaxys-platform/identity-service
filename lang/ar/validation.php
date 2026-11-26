<?php

// رسائل التحقق المترجمة: المجموعة الفرعية المستخدمة بواسطة قواعد Identity V2.
// توفر إطار العمل الافتراضيات بالإنجليزية.
return [
    'required' => 'الحقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'max' => [
        'string' => 'يجب ألا يتجاوز :attribute :max حرفًا.',
        'integer' => 'يجب ألا يتجاوز :attribute :max.',
    ],
    'min' => [
        'string' => 'يجب أن يكون :attribute :min حرفًا على الأقل.',
        'integer' => 'يجب أن يكون :attribute :min على الأقل.',
    ],
    'in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'email' => 'يجب أن يكون :attribute عنوان بريد إلكتروني صالحًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صالحة.',
    'regex' => 'تنسيق :attribute غير صالح.',
];
