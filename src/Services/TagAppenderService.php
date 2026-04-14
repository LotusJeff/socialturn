<?php
declare(strict_types=1);

namespace SocialTurn\Services;

/**
 * TagAppenderService
 *
 * Appends hashtags to a post body string without exceeding the platform
 * character limit. Tags are sourced from accounts.default_tags (stored as
 * a JSON array without # prefixes) and appended in priority order.
 *
 * No database dependency — operates purely on strings passed to it.
 */
class TagAppenderService
{
    private const DEFAULT_LIMITS = [
        'twitter'   => 280,
        'facebook'  => 63206,
        'instagram' => 2200,
    ];

    public function __construct(
        private readonly array $platformLimits = self::DEFAULT_LIMITS
    ) {}

    /**
     * Append as many tags as fit within the platform character limit.
     *
     * @param string            $body     The original post body — never truncated
     * @param string|array|null $tags     JSON string or decoded array from accounts.default_tags
     * @param string            $platform Platform identifier: twitter|facebook|instagram
     *
     * @return array{
     *     body:          string,
     *     tags_appended: int,
     *     tags_skipped:  int,
     *     error:         string|null
     * }
     */
    public function append(string $body, string|array|null $tags, string $platform): array
    {
        $result = [
            'body'          => $body,
            'tags_appended' => 0,
            'tags_skipped'  => 0,
            'error'         => null,
        ];

        $limit = $this->resolveLimit($platform);

        if ($limit === null) {
            $result['error'] = "Unrecognized platform: '{$platform}'.";
            return $result;
        }

        $parsed = $this->parseTags($tags);

        if ($parsed === []) {
            return $result;
        }

        if (isset($parsed['error'])) {
            $result['error'] = $parsed['error'];
            return $result;
        }

        $cursor = $body;

        foreach ($parsed['tags'] as $tag) {
            $candidate = $cursor . ' #' . $tag;

            if (mb_strlen($candidate) <= $limit) {
                $cursor = $candidate;
                $result['tags_appended']++;
            } else {
                // Once a tag does not fit, all remaining tags are counted as
                // skipped without testing. This is intentional — priority order
                // is preserved over maximum tag count.
                $result['tags_skipped'] += count($parsed['tags']) - $result['tags_appended'];
                break;
            }
        }

        $result['body'] = $cursor;

        return $result;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Normalizes tags input to a plain array of strings.
     * Accepts a JSON string, a pre-decoded array, or null/empty.
     * Returns an empty array for any null, empty, or unparseable input.
     *
     * @return array{tags: list<string>}|array{tags: list<string>, error: string}|array{}
     */
    private function parseTags(string|array|null $tags): array
    {
        if ($tags === null || $tags === '' || $tags === []) {
            return [];
        }

        if (is_string($tags)) {
            $decoded = json_decode($tags, true);

            if (!is_array($decoded)) {
                return ['tags' => [], 'error' => 'default_tags JSON is malformed or not an array.'];
            }

            $tags = $decoded;
        }

        $cleaned = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $cleaned[] = $tag;
            }
        }

        return ['tags' => $cleaned];
    }

    /**
     * Returns the character limit for the given platform.
     * Returns null for unrecognized platforms.
     */
    private function resolveLimit(string $platform): ?int
    {
        return $this->platformLimits[$platform] ?? null;
    }
}
