<?php

namespace App\Auth;

use Illuminate\Session\DatabaseSessionHandler;

final class PrivateDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function addRequestInformation(&$payload)
    {
        // Do not store raw IP, user-agent or arbitrary request metadata.
        return $this;
    }
}
