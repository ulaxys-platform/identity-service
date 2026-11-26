<?php

// Explicit test translations, not labels for services created from this template.
$config = require __DIR__.'/metadata.php';
$translations = [
    'index' => ['tr' => 'Listeleme İzni', 'en' => 'Listing Permission', 'ar' => 'إذن عرض القائمة'],
    'show' => ['tr' => 'Görüntüleme İzni', 'en' => 'Viewing Permission', 'ar' => 'إذن العرض'],
    'create' => ['tr' => 'Oluşturma İzni', 'en' => 'Creation Permission', 'ar' => 'إذن الإنشاء'],
    'edit' => ['tr' => 'Düzenleme İzni', 'en' => 'Editing Permission', 'ar' => 'إذن التعديل'],
    'delete' => ['tr' => 'Silme İzni', 'en' => 'Deletion Permission', 'ar' => 'إذن الحذف'],
];
foreach ($config['modules'] as &$module) {
    foreach ($module['permissions']['actions'] as &$action) {
        $action['description'] = $translations[$action['action']];
    }
    unset($action);
}
unset($module);

return $config;
