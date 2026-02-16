<?php

namespace App\Support\GoogleWorkspace\Contracts;

interface WorkspaceUserDirectory
{
    public function userExists(string $email): bool;
}
