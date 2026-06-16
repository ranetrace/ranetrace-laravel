<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

enum CheckLevel: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
