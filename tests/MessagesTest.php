<?php
/**
 * Every line the five tools print is in the README, as the CHANGELOG promises — a message added
 * to a tool is easily missed in the README, so this test holds it. Each message literal of
 * bin/genba-smoke and src/{Stamp,Preflight,Precheck,Tree,Links}.php — the constant text between
 * the values a message is built of — must stand in the README section of its tool
 * (## genba-<tool>), compared with whitespace collapsed and without the [ ] the README uses for
 * optional parts. Fragments with fewer than ten letters (": ", " — ", "ok") are not compared.
 *
 * What counts as a message literal:
 *   PHP   a string in a fwrite(), cannotRun() or exception call up to its `;`; a string after one
 *         of the keys 'text', 'refused', 'message', 'reason', 'error' up to the next `,` or `]` at
 *         the same depth; a string assigned to $why or added to $failures. Split at \n and at the
 *         sprintf conversions; regular expressions (~…~i, /…/) are skipped.
 *   bash  the double-quoted argument of fail, warn, ok, note, cannot_run and echo, and
 *         message="…", split at every $name, ${…} and $(…).
 *
 *   php tests/MessagesTest.php
 */
require_once __DIR__ . '/helpers.php';

const MESSAGES_TOOLS = ['genba-stamp', 'genba-preflight', 'genba-precheck', 'genba-smoke', 'genba-links'];

/** Whitespace collapsed, [ and ] dropped: how literal and README are compared. */
function messages_normal(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', str_replace(['[', ']'], '', $text)));
}

/** @return array<string,string> tool => its README section, normalised */
function messages_sections(string $readme): array
{
    $sections = [];
    foreach (MESSAGES_TOOLS as $tool) {
        $from = strpos($readme, "\n## $tool\n");
        $to = $from === false ? false : strpos($readme, "\n## ", $from + 4);
        $sections[$tool] = $from === false ? '' : messages_normal(substr($readme, $from, $to === false ? null : $to - $from));
    }
    return $sections;
}

/** @return list<string> the fragments of one literal worth comparing */
function messages_fragments(string $literal): array
{
    $fragments = [];
    foreach (preg_split('/\\\\n|%[-+ 0-9.]*[sdf]/', $literal) ?: [] as $piece) {
        $piece = messages_normal($piece);
        if (preg_match_all('/[A-Za-z]/', $piece) >= 10) {
            $fragments[] = $piece;
        }
    }
    return $fragments;
}

/**
 * The message literals of a PHP source.
 *
 * @return list<array{line: int, text: string}>
 */
function messages_php(string $source): array
{
    $found = [];
    $context = null;
    $depth = 0;
    $keyDepth = null;
    $pendingKey = false;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($context === 'key' && $depth < $keyDepth) {
                    $context = null;
                }
            } elseif ($token === ',' && $context === 'key' && $depth === $keyDepth) {
                $context = null;
            } elseif ($token === ';') {
                $context = null;
            }
            continue;
        }
        [$id, $text, $line] = $token;
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        if ($pendingKey) {
            $pendingKey = false;
            if ($id === T_DOUBLE_ARROW) {
                $context = 'key';
                $keyDepth = $depth;
                continue;
            }
        }
        if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && in_array(ltrim($text, '\\'), ['fwrite', 'cannotRun', 'RuntimeException', 'InvalidArgumentException'], true)) {
            $context = 'call';
            continue;
        }
        if ($id === T_VARIABLE && in_array($text, ['$why', '$failures'], true)) {
            $context = 'call';
            continue;
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING && $context === null && in_array(substr($text, 1, -1), ['text', 'refused', 'message', 'reason', 'error'], true)) {
            $pendingKey = true;
            continue;
        }
        if ($context === null || ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE)) {
            continue;
        }
        $literal = $id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
        if (preg_match('#^([~/]).*\1[a-z]*$#s', $literal) === 1) {
            continue;
        }
        $found[] = ['line' => $line, 'text' => str_replace(["\\'", '\\"'], ["'", '"'], $literal)];
    }
    return $found;
}

/**
 * The message literals of a bash source.
 *
 * @return list<array{line: int, text: string}>
 */
function messages_bash(string $source): array
{
    $found = [];
    foreach (explode("\n", $source) as $index => $line) {
        if (preg_match('/\b(?:fail|warn|ok|note|cannot_run|echo)\s+"(.*)"\s*;?\s*$|^\s*message="(.*)"\s*$/', $line, $m) !== 1) {
            continue;
        }
        $literal = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        foreach (preg_split('/\$\([^)]*\)|\$\{[^}]*\}|\$[A-Za-z_][A-Za-z_0-9]*/', $literal) ?: [] as $piece) {
            $found[] = ['line' => $index + 1, 'text' => str_replace('\\"', '"', $piece)];
        }
    }
    return $found;
}

/**
 * @param list<array{line: int, text: string}> $literals
 * @param list<string>                         $tools
 * @param array<string,string>                 $sections
 * @return list<string> "<file>:<line> (<tool>): <fragment>" for every fragment a section lacks
 */
function messages_missing(string $file, array $literals, array $tools, array $sections): array
{
    $missing = [];
    foreach ($literals as $literal) {
        foreach (messages_fragments($literal['text']) as $fragment) {
            foreach ($tools as $tool) {
                if (!str_contains($sections[$tool] ?? '', $fragment)) {
                    $missing[] = "$file:{$literal['line']} ($tool): $fragment";
                }
            }
        }
    }
    return $missing;
}

$root = dirname(__DIR__);
$sections = messages_sections((string) file_get_contents("$root/README.md"));
foreach (MESSAGES_TOOLS as $tool) {
    holds($sections[$tool] !== '', "README has a section ## $tool");
}

// The check proves itself first: a message that is in no section is found, in PHP and in bash,
// and one that is there is not — a reader that finds nothing must not read as "all documented".
$probe = messages_missing('probe.php', messages_php('<?php fwrite($out, "::error::Genba probe message nobody documented: $x\n"); '
    . "return ['status' => 1, 'text' => 'Parameters lost on the way: ' . \$y];"), ['genba-links'], $sections);
expect(['probe.php:1 (genba-links): ::error::Genba probe message nobody documented:'], $probe, 'the PHP reader finds an undocumented message and only that');
$probe = messages_missing('probe.sh', messages_bash("  fail \"Genba probe finding nobody documented: \$x\"\n  ok \"Stylesheet: 200, text/css\""), ['genba-smoke'], $sections);
expect(['probe.sh:1 (genba-smoke): Genba probe finding nobody documented:'], $probe, 'the bash reader finds an undocumented message and only that');

$owners = [
    'src/Stamp.php' => ['genba-stamp'],
    'src/Preflight.php' => ['genba-preflight'],
    'src/Precheck.php' => ['genba-precheck'],
    'src/Tree.php' => ['genba-precheck', 'genba-links'],
    'src/Links.php' => ['genba-links'],
    'src/Homepage.php' => ['genba-smoke'],
    'src/Text.php' => ['genba-stamp', 'genba-preflight', 'genba-precheck', 'genba-smoke', 'genba-links'],
];
$count = 0;
$missing = [];
foreach ($owners as $file => $tools) {
    $literals = messages_php((string) file_get_contents("$root/$file"));
    $count += count($literals);
    array_push($missing, ...messages_missing($file, $literals, $tools, $sections));
}
$literals = messages_bash((string) file_get_contents("$root/bin/genba-smoke"));
$count += count($literals);
array_push($missing, ...messages_missing('bin/genba-smoke', $literals, ['genba-smoke'], $sections));
holds($count > 300, "the readers find the messages of the five tools ($count literals)");
expect([], $missing, 'every message fragment of the five tools stands in the README section of its tool');
echo "  ok   $count message literals checked against the README\n";

// The functions of genba-smoke's earlier awk reader, replaced by the PHP homepage reader (SPEC §3.4,
// "Reading the homepage"), are gone: none is defined, and a line in the code or the tests that names
// one does so only as history.
$removed = ['strip_markup', 'tag_space', 'start_tag', 'tag_attributes', 'build_of', 'flatten_html'];
$current = [];
$sources = array_merge(['bin/genba-smoke', 'bin/genba-smoke-read'], array_map(static fn (string $f): string => substr($f, strlen($root) + 1),
    array_merge(glob("$root/src/*.php") ?: [], glob("$root/tests/*.php") ?: [], glob("$root/tests/fixtures/*.php") ?: [])));
foreach ($sources as $file) {
    if ($file === 'tests/MessagesTest.php') {
        continue;
    }
    foreach (explode("\n", (string) file_get_contents("$root/$file")) as $index => $line) {
        foreach ($removed as $name) {
            if (preg_match('/\b' . $name . '\b/', $line) === 1 && (stripos($line, 'round') === false || preg_match('/^\s*' . $name . '\s*\(\)/', $line) === 1)) {
                $current[] = "$file:" . ($index + 1) . " ($name)";
            }
        }
    }
}
holds(count($sources) > 20, 'the code and the tests are read (' . count($sources) . ' files)');
expect([], $current, 'a function of the earlier awk reader is never defined, and named only as history');

// The usage of genba-smoke says what GENBA_MAX_TIME bounds, as the README does: a request and the homepage
// reader. The entry with its continuation lines, read from the usage text in the script (the same heredoc
// --help prints).
$smoke = (string) file_get_contents("$root/bin/genba-smoke");
holds(preg_match('/^  GENBA_MAX_TIME {2,}(.+(?:\n {20,}\S.*)*)/m', $smoke, $entry) === 1, 'the usage of genba-smoke has a GENBA_MAX_TIME entry');
$usage = (string) preg_replace('/\s+/', ' ', $entry[1] ?? '');
holds(str_contains($usage, 'request') && str_contains($usage, 'homepage reader'), "the usage names both bounds of GENBA_MAX_TIME, a request and the homepage reader: $usage");
holds(preg_match('/^\| `GENBA_MAX_TIME` \|[^\n]*one request[^\n]*reader of the homepage/m', (string) file_get_contents("$root/README.md")) === 1,
    'the README row of GENBA_MAX_TIME names both bounds too');

finish('Messages');
