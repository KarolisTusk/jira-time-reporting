<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetupTestUser extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'setup:test-user {--email=admin@jira-reporter.local} {--password=password123} {--name=Admin User}';

    /**
     * The console command description.
     */
    protected $description = 'Create or update a test user for login';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');
        $name = $this->option('name');

        $this->info('🔧 Setting up test user credentials');
        $this->newLine();

        // Check if user exists
        $user = User::where('email', $email)->first();

        if ($user) {
            $this->info("User with email '{$email}' already exists. Updating password...");
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
            ]);
            $action = 'updated';
        } else {
            $this->info("Creating new user with email '{$email}'...");
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);
            $action = 'created';
        }

        $this->newLine();
        $this->info("✅ Test user {$action} successfully!");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Password', $password],
                ['Login URL', 'http://127.0.0.1:8001/login'],
            ]
        );

        $this->newLine();
        $this->info('🔗 You can now login at: http://127.0.0.1:8001/login');
        $this->info('📧 Email: ' . $user->email);
        $this->info('🔑 Password: ' . $password);

        return self::SUCCESS;
    }
}