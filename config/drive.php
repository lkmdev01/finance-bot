<?php

return [
    // Folder created in the user's Google Drive to keep app-managed files.
    'root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER', 'InovaFinance'),

    // Default taxonomy for auto-organization. Used when no explicit folder is provided.
    'default_folders' => [
        'comprovantes' => 'Comprovantes',
        'contratos' => 'Contratos',
        'fotos' => 'Fotos',
        'documentos' => 'Documentos',
        'audios' => 'Audios',
    ],
];

