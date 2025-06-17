<?php

use App\Http\Controllers\JiraSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController; // Added import
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // JIRA Settings Routes
    Route::get('settings/jira', [JiraSettingsController::class, 'show'])->name('settings.jira.show');
    Route::post('settings/jira', [JiraSettingsController::class, 'store'])->name('settings.jira.store');
    Route::post('settings/jira/test', [JiraSettingsController::class, 'testConnection'])->name('settings.jira.test');
    Route::get('settings/jira/projects', [JiraSettingsController::class, 'fetchProjects'])->name('settings.jira.projects');
});
