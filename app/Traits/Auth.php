<?php

namespace App\Traits;

trait Auth
{
    /**
     * Check if user is authenticated
     *
     * @return bool Returns true if authenticated, false if not
     */
    protected function checkAuth(): bool
    {
        if (!$this->auth?->hasApiKey()) {
            $this->error('You need to run "auth login" first.');
            return false;
        }

        return true;
    }

    /**
     * Determine if the command requires authentication
     *
     * @return bool
     */
    protected function requiresAuth(): bool
    {
        if ($this->option('help') || !$this->argument('action')) {
            return false;
        }

        return true;
    }
}
