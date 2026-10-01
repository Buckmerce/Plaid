#!/usr/bin/env bash
# Translation gate: every bundled translation (languages/buckmerce-for-plaid-<locale>.po) must
#   - contain exactly the strings of the POT template (nothing missing, nothing obsolete);
#   - translate every string (no empty or fuzzy entries) with the same printf placeholders;
#   - declare its locale and plural forms;
#   - ship compiled files (.mo and .l10n.php, built with `wp i18n make-mo` / `make-php`) that
#     hold the same messages as the .po.
# With WP-CLI installed it also regenerates the template from the sources and requires the
# committed POT to contain exactly those strings, so a string changed in the code without
# rebuilding the translations fails here. BUCKMERCE_PLAID_REQUIRE_WP_CLI=1 (CI) makes a missing
# WP-CLI a failure instead of a skipped template check.
# Rebuild everything with: bash scripts/build-translations.sh
set -euo pipefail

base_dir=$(cd "$(dirname "$0")/.." && pwd)
bmfp_base_dir=$base_dir
# shellcheck source=lib/i18n.sh
. "$base_dir/scripts/lib/i18n.sh"

fresh=''
if command -v wp >/dev/null 2>&1; then
    fresh=$(mktemp)
    trap 'rm -f "$fresh"' EXIT
    bmfp_make_pot "$fresh"
elif [[ "${BUCKMERCE_PLAID_REQUIRE_WP_CLI:-0}" == 1 ]]; then
    printf 'TRANSLATION CHECK FAILED: WP-CLI (wp) is required to verify the template against the sources.\n' >&2
    exit 1
else
    printf 'Note: WP-CLI is not installed; the template was not compared with the sources.\n' >&2
fi

php -- "$base_dir/languages" "$bmfp_i18n_domain" "$fresh" <<'PHP'
<?php
[, $dir, $domain, $fresh_pot] = $argv;
$failures = 0;
// The STDERR constant is not defined for a script read from standard input, and a php://stderr
// handle that is closed takes the real descriptor with it: open it once and keep it.
$stderr = fopen('php://stderr', 'w');
$fail = static function (string $message) use (&$failures, $stderr): void {
    fwrite($stderr, 'TRANSLATION CHECK FAILED: ' . $message . "\n");
    ++$failures;
};
$unquote = static fn (string $quoted): string => stripcslashes(substr(trim($quoted), 1, -1));

/** @return array{messages: array<string, string>, fuzzy: list<string>, header: string} */
$read_po = static function (string $file) use ($unquote): array {
    $messages = array();
    $fuzzy = array();
    $id = null;
    $str = '';
    $mode = '';
    $is_fuzzy = false;
    $flush = static function () use (&$messages, &$fuzzy, &$id, &$str, &$is_fuzzy): void {
        if (null !== $id) {
            $messages[$id] = $str;
            if ($is_fuzzy) {
                $fuzzy[] = $id;
            }
        }
        $id = null;
        $str = '';
        $is_fuzzy = false;
    };
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if ('' === trim($line)) {
            $flush();
            $mode = '';
        } elseif (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
            $is_fuzzy = true;
        } elseif ('#' === $line[0]) {
            continue;
        } elseif (str_starts_with($line, 'msgctxt ') || str_starts_with($line, 'msgid_plural ')) {
            throw new RuntimeException(basename($file) . ': contexts and plural entries are not supported by this check; extend it first.');
        } elseif (str_starts_with($line, 'msgid ')) {
            $id = $unquote(substr($line, 6));
            $mode = 'id';
        } elseif (str_starts_with($line, 'msgstr ')) {
            $str = $unquote(substr($line, 7));
            $mode = 'str';
        } elseif ('"' === $line[0] && 'id' === $mode) {
            $id .= $unquote($line);
        } elseif ('"' === $line[0] && 'str' === $mode) {
            $str .= $unquote($line);
        }
    }
    $flush();
    $header = $messages[''] ?? '';
    unset($messages['']);
    return array('messages' => $messages, 'fuzzy' => $fuzzy, 'header' => $header);
};

/** @return array<string, string> */
$read_mo = static function (string $file): array {
    $data = (string) file_get_contents($file);
    $magic = unpack('V', substr($data, 0, 4))[1] ?? 0;
    $format = 0x950412de === $magic ? 'V' : (0xde120495 === $magic ? 'N' : '');
    if ('' === $format) {
        throw new RuntimeException(basename($file) . ' is not a MO file.');
    }
    [, $count, $ids_at, $strs_at] = array_values(unpack($format . '4', substr($data, 4, 16)));
    $messages = array();
    for ($i = 0; $i < $count; ++$i) {
        [$id_length, $id_offset] = array_values(unpack($format . '2', substr($data, $ids_at + 8 * $i, 8)));
        [$str_length, $str_offset] = array_values(unpack($format . '2', substr($data, $strs_at + 8 * $i, 8)));
        $messages[substr($data, $id_offset, $id_length)] = substr($data, $str_offset, $str_length);
    }
    unset($messages['']);
    return $messages;
};

/** Same messages regardless of their order in the file (a MO file is sorted by msgid). */
$same = static function (array $left, array $right): bool {
    ksort($left, SORT_STRING);
    ksort($right, SORT_STRING);
    return $left === $right;
};

$placeholders = static function (string $text): array {
    preg_match_all('/%(?:\d+\$)?[sdf]/', $text, $matches);
    $found = $matches[0];
    // Numbered placeholders may be reordered by a translation; unnumbered ones may not.
    if (array() !== array_filter($found, static fn (string $placeholder): bool => str_contains($placeholder, '$'))) {
        sort($found);
    }
    return $found;
};

$pot_file = $dir . '/' . $domain . '.pot';
if (! is_file($pot_file)) {
    $fail('missing template ' . basename($pot_file));
    exit(1);
}
$template = array_keys($read_po($pot_file)['messages']);
if ('' !== $fresh_pot) {
    // The committed template must hold exactly the strings of the current sources.
    $current = array_keys($read_po($fresh_pot)['messages']);
    foreach (array_diff($current, $template) as $added) {
        $fail('source string missing from the template (run scripts/build-translations.sh): ' . substr($added, 0, 70));
    }
    foreach (array_diff($template, $current) as $removed) {
        $fail('template string no longer in the sources (run scripts/build-translations.sh): ' . substr($removed, 0, 70));
    }
}
$summary = array();
foreach (glob($dir . '/' . $domain . '-*.po') ?: array() as $po_file) {
    $locale = substr(basename($po_file, '.po'), strlen($domain) + 1);
    $po = $read_po($po_file);
    foreach (array_diff($template, array_keys($po['messages'])) as $missing) {
        $fail("$locale: string missing from the .po (run wp i18n update-po): " . substr($missing, 0, 70));
    }
    foreach (array_diff(array_keys($po['messages']), $template) as $obsolete) {
        $fail("$locale: string no longer in the template: " . substr($obsolete, 0, 70));
    }
    foreach ($po['messages'] as $id => $translation) {
        if ('' === trim($translation)) {
            $fail("$locale: untranslated: " . substr($id, 0, 70));
        } elseif ($placeholders($id) !== $placeholders($translation)) {
            $fail("$locale: placeholders differ: " . substr($id, 0, 70));
        }
    }
    foreach ($po['fuzzy'] as $id) {
        $fail("$locale: fuzzy entry: " . substr($id, 0, 70));
    }
    if (! str_contains($po['header'], "Language: $locale\n") || ! str_contains($po['header'], 'Plural-Forms: ')) {
        $fail("$locale: the .po header must declare Language: $locale and Plural-Forms");
    }
    $base = substr($po_file, 0, -3);
    if (! is_file($base . '.mo')) {
        $fail("$locale: missing compiled " . basename($base) . '.mo (run wp i18n make-mo languages)');
    } elseif (! $same($read_mo($base . '.mo'), $po['messages'])) {
        $fail("$locale: " . basename($base) . '.mo is stale (run wp i18n make-mo languages)');
    }
    if (! is_file($base . '.l10n.php')) {
        $fail("$locale: missing compiled " . basename($base) . '.l10n.php (run wp i18n make-php languages)');
    } else {
        $compiled = include $base . '.l10n.php';
        if (! is_array($compiled) || ! is_array($compiled['messages'] ?? null) || ! $same($compiled['messages'], $po['messages'])) {
            $fail("$locale: " . basename($base) . '.l10n.php is stale (run wp i18n make-php languages)');
        }
    }
    $summary[] = sprintf('%s (%d strings)', $locale, count($po['messages']));
}
if ($failures > 0) {
    fwrite($stderr, sprintf("Translation check: %d problem(s).\n", $failures));
    exit(1);
}
printf("Translation check passed: %s; template has %d strings%s.\n", array() === $summary ? 'no bundled translations' : implode(', ', $summary), count($template), '' === $fresh_pot ? '' : ' and matches the sources');
PHP
