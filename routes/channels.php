<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;

/**
 * Check if user has admin permissions.
 * This mirrors the logic from AdminMiddleware for consistency.
 */
if (!function_exists('checkAdminPermissions')) {
    function checkAdminPermissions($user): bool
    {
        if (!$user) {
            return false;
        }

        // Check for admin indicators
        $email = strtolower($user->email ?? '');
        
        // Allow admin emails
        if (str_contains($email, 'admin')) {
            return true;
        }
        
        // Check for specific admin domains
        $adminDomains = [
            'admin.com',
            'management.com',
            // Add your admin domains here
        ];
        
        foreach ($adminDomains as $domain) {
            if (str_ends_with($email, '@' . $domain)) {
                return true;
            }
        }
        
        // For development purposes, allow all authenticated users
        // Remove this in production!
        if (app()->environment('local', 'development')) {
            return true;
        }
        
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| JIRA Sync Progress Channels
|--------------------------------------------------------------------------
|
| These channels are used for broadcasting real-time JIRA sync progress
| updates to users. Only the user who triggered the sync can listen to
| their own sync progress updates.
|
*/

Broadcast::channel('jira-sync.{userId}', function ($user, $userId) {
    // Users can only listen to their own sync progress
    return Auth::check() && (int) $user->id === (int) $userId;
});

/*
|--------------------------------------------------------------------------
| Enhanced JIRA Sync Progress Channels
|--------------------------------------------------------------------------
|
| These channels are used for broadcasting enhanced real-time JIRA sync
| progress updates with detailed metrics and project-level progress.
|
*/

Broadcast::channel('enhanced-jira-sync.{userId}', function ($user, $userId) {
    // Users can only listen to their own enhanced sync progress
    return Auth::check() && (int) $user->id === (int) $userId;
});

/*
|--------------------------------------------------------------------------
| Admin Channels
|--------------------------------------------------------------------------
|
| These channels are used for admin-level broadcasting such as
| system-wide notifications and monitoring all sync operations.
|
*/

Broadcast::channel('admin-sync-progress', function ($user) {
    // Only admin users can listen to all sync progress updates
    return Auth::check() && checkAdminPermissions($user);
});

Broadcast::channel('admin-sync-metrics', function ($user) {
    // Only admin users can listen to sync metrics updates
    return Auth::check() && checkAdminPermissions($user);
});

/*
|--------------------------------------------------------------------------
| Global Sync Status Channels
|--------------------------------------------------------------------------
|
| These channels provide system-wide sync status information for
| authenticated users with appropriate permissions.
|
*/

Broadcast::channel('sync-status', function ($user) {
    // All authenticated users can listen to general sync status
    return Auth::check();
});

Broadcast::channel('sync-notifications', function ($user) {
    // All authenticated users can receive sync notifications
    return Auth::check();
});
