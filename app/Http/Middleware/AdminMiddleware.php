<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required',
                ], 401);
            }
            
            return redirect()->route('login')->with('error', 'Authentication required for admin access.');
        }

        // Check if user has admin permissions
        $user = auth()->user();
        
        // For now, we'll check if the user's email contains 'admin' or has specific roles
        // In production, you'd want a proper role/permission system
        $isAdmin = $this->checkAdminPermissions($user);
        
        if (!$isAdmin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required',
                ], 403);
            }
            
            abort(403, 'Admin access required. Please contact your administrator.');
        }

        return $next($request);
    }

    /**
     * Check if user has admin permissions.
     */
    protected function checkAdminPermissions($user): bool
    {
        // Basic admin check - in production you'd use a proper role system
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
        
        // Check for admin role if you have a roles system
        // if ($user->hasRole('admin') || $user->hasRole('manager')) {
        //     return true;
        // }
        
        // Check for specific admin permissions
        // if ($user->can('manage-jira-sync')) {
        //     return true;
        // }
        
        // For development purposes, allow all authenticated users
        // Remove this in production!
        if (app()->environment('local', 'development')) {
            return true;
        }
        
        return false;
    }
}
