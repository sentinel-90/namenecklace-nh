<?php

namespace App\Support;

use OverflowException;

final class JsonPreview
{
    private const MAX_EXTRA_BYTES = 2 * 1024 * 1024;

    public static function format(string $json): string
    {
        if (! json_validate($json)) {
            return $json;
        }

        // Match strings whole so formatting leaves their contents and numeric values intact.
        $tokens = <<<'REGEX'
        ~"(?:[^"\\]++|\\.)*+"|\{\s*\}|\[\s*\]|[{}\[\],:]|\s+~
        REGEX;
        $depth = 0;
        $extraBytes = 0;

        try {
            return preg_replace_callback($tokens, function (array $matches) use (&$depth, &$extraBytes): string {
                $token = $matches[0];
                $formatted = match ($token) {
                    '{', '[' => $token."\n".str_repeat('    ', ++$depth),
                    '}', ']' => "\n".str_repeat('    ', --$depth).$token,
                    ',' => ",\n".str_repeat('    ', $depth),
                    ':' => ': ',
                    default => match ($token[0]) {
                        '"' => $token,
                        '{' => '{}',
                        '[' => '[]',
                        default => '',
                    },
                };

                $extraBytes += strlen($formatted) - strlen($token);

                if ($extraBytes > self::MAX_EXTRA_BYTES) {
                    throw new OverflowException;
                }

                return $formatted;
            }, $json) ?? $json;
        } catch (OverflowException) {
            return $json;
        }
    }
}
