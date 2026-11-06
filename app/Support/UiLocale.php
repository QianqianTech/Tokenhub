<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class UiLocale
{
    public static function normalize(mixed $value): string
    {
        return $value === 'en-US' ? 'en-US' : 'zh-CN';
    }

    public static function fromRequest(Request $request): string
    {
        return self::normalize($request->header('X-TokenHub-Locale'));
    }
}
