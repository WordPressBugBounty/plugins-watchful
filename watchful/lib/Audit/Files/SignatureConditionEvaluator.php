<?php

namespace Watchful\Audit\Files;

use stdClass;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Evaluates signatures of type "condition": the master publishes, per
 * signature, a set of PCRE string fragments plus a small boolean AST over
 * their occurrence counts (any/N-of-them/count/filesize/offset). This class
 * never parses YARA, it only walks the AST the master already produced.
 *
 * Unlike the other signature types, a single "condition" signature needs to
 * read every one of its strings against the file content before it can
 * decide on a hit, so the counting is memoized per file (a string used by
 * more than one node in the AST is only matched once).
 */
class SignatureConditionEvaluator
{
    /** @var array<int|string, array{strings: array, condition: array}|false> decoded condition_meta, keyed by signature id */
    private $decoded = [];

    /**
     * @return array|null null when the signature does not match this file
     */
    public function evaluate(stdClass $signature, string $contents, string $file, string $path_from_root)
    {
        $meta = $this->decode($signature);
        if (false === $meta) {
            return null;
        }

        $counts = [];
        $first_match = [];

        if (!$this->eval_node($meta['condition'], $meta['strings'], $contents, $file, $counts, $first_match)) {
            return null;
        }

        return [
            'path' => $path_from_root,
            'match' => substr(implode(' ', $first_match) ?: $signature->reason, 0, 50),
            'reason' => $signature->reason,
        ];
    }

    /**
     * @return array{strings: array, condition: array}|false
     */
    private function decode(stdClass $signature)
    {
        if (array_key_exists($signature->id, $this->decoded)) {
            return $this->decoded[$signature->id];
        }

        $meta = json_decode((string) ($signature->condition_meta ?? ''), true);
        $valid = is_array($meta) && isset($meta['strings'], $meta['condition']) && is_array($meta['strings']);

        return $this->decoded[$signature->id] = $valid ? $meta : false;
    }

    /**
     * @param array<string, array{pattern:string, anchor:?string}> $strings
     * @param array<string, int>    $counts      memoized occurrence count per string ref, for this file
     * @param array<string, string> $first_match memoized first occurrence per string ref, for this file
     */
    private function eval_node(array $node, array $strings, string $contents, string $file, array &$counts, array &$first_match): bool
    {
        switch ($node['op']) {
            case 'present':
                return $this->count_of($node['ref'], $strings, $contents, $counts, $first_match) > 0;

            case 'any':
                foreach ($node['refs'] as $ref) {
                    if ($this->count_of($ref, $strings, $contents, $counts, $first_match) > 0) {
                        return true;
                    }
                }

                return false;

            case 'count_of':
                $hit = 0;
                foreach ($node['refs'] as $ref) {
                    if ($this->count_of($ref, $strings, $contents, $counts, $first_match) > 0) {
                        $hit++;
                    }
                }

                return $hit >= $node['n'];

            case 'count_cmp':
                return $this->compare($this->count_of($node['ref'], $strings, $contents, $counts, $first_match), $node['cmp'], $node['n']);

            case 'filesize_cmp':
                return $this->compare((int) @filesize($file), $node['cmp'], $node['bytes']);

            case 'at_offset':
                $pattern = $strings[$node['ref']]['pattern'] ?? null;
                if (null === $pattern) {
                    return false;
                }
                $hit = (bool) preg_match('#^'.$pattern.'#i', $contents, $m);
                if ($hit) {
                    $first_match[$node['ref']] = $m[0];
                }

                return $hit;

            case 'and':
                foreach ($node['args'] as $arg) {
                    if (!$this->eval_node($arg, $strings, $contents, $file, $counts, $first_match)) {
                        return false;
                    }
                }

                return true;

            case 'or':
                foreach ($node['args'] as $arg) {
                    if ($this->eval_node($arg, $strings, $contents, $file, $counts, $first_match)) {
                        return true;
                    }
                }

                return false;

            case 'not':
                return !$this->eval_node($node['arg'], $strings, $contents, $file, $counts, $first_match);

            default:
                return false; // unknown node from a future master: fail closed, no hit
        }
    }

    /**
     * @param array<string, array{pattern:string, anchor:?string}> $strings
     */
    private function count_of(string $ref, array $strings, string $contents, array &$counts, array &$first_match): int
    {
        if (isset($counts[$ref])) {
            return $counts[$ref];
        }

        $pattern = $strings[$ref]['pattern'] ?? null;
        if (null === $pattern) {
            return $counts[$ref] = 0;
        }

        $matched = preg_match_all('#'.$pattern.'#i', $contents, $matches);
        if ($matched) {
            $first_match[$ref] = $matches[0][0];
        }

        return $counts[$ref] = (int) $matched;
    }

    /**
     * @param int|string $threshold
     */
    private function compare(int $value, string $cmp, $threshold): bool
    {
        switch ($cmp) {
            case '<':
                return $value < $threshold;
            case '<=':
                return $value <= $threshold;
            case '>':
                return $value > $threshold;
            case '>=':
                return $value >= $threshold;
            case '==':
                return $value == $threshold;
            default:
                return false;
        }
    }
}
