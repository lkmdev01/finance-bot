<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class GoogleDriveController extends Controller
{
    public function index(GoogleDriveService $drive): View
    {
        $user = Auth::user();
        $connection = $user?->googleDriveConnection;

        $rootFolderId = null;
        if ($user && $connection && ! $connection->revoked_at) {
            try {
                $rootFolderId = $drive->ensureRootFolder($user);
            } catch (\Throwable) {
                $rootFolderId = $connection->root_folder_id;
            }
        }

        return view('pages.integrations.google-drive', [
            'connection' => $connection,
            'rootFolderId' => $rootFolderId,
        ]);
    }

    public function disconnect(): RedirectResponse
    {
        $user = Auth::user();
        $connection = $user?->googleDriveConnection;

        if ($connection) {
            $connection->forceFill([
                'revoked_at' => now(),
            ])->save();
        }

        return redirect()->route('integrations.google-drive')
            ->with('message', 'Google Drive desconectado.');
    }
}

