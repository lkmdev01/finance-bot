<?php

namespace App\Services\WhatsApp;

class GoogleDriveServiceLinkHelper
{
    public function webUrl(string $driveFileId): string
    {
        return 'https://drive.google.com/file/d/'.rawurlencode($driveFileId).'/view';
    }
}

