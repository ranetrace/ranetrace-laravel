<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * The outcome of a single misconfiguration check: a level, a one-line title,
 * and (for warn/fail) a one-line remediation. Read-only value object.
 */
final class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly CheckLevel $level,
        public readonly string $title,
        public readonly string $remediation = '',
    ) {}

    public static function pass(string $name, string $title): self
    {
        return new self($name, CheckLevel::Pass, $title);
    }

    public static function warn(string $name, string $title, string $remediation = ''): self
    {
        return new self($name, CheckLevel::Warn, $title, $remediation);
    }

    public static function fail(string $name, string $title, string $remediation = ''): self
    {
        return new self($name, CheckLevel::Fail, $title, $remediation);
    }
}
