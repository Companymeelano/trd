<?php
declare(strict_types=1);

namespace App\Core;

final class Decision
{
    public const ACCEPT = 'ACCEPT';
    public const REJECT = 'REJECT';
    public const WATCH  = 'WATCH';

    private function __construct() {}
}
