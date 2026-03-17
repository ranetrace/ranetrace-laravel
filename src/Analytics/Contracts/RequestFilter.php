<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics\Contracts;

use Illuminate\Http\Request;

interface RequestFilter
{
    public function shouldSkip(Request $request): bool;
}
