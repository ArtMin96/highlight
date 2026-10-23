<?php

declare(strict_types=1);

namespace Tempest\Highlight\Languages\Python\Patterns;

use Generator;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\PatternTest;
use Tempest\Highlight\RequiresSubstring;
use Tempest\Highlight\Tokens\TokenTypeEnum;

#[PatternTest(input: 'case _:', output: '_')]
#[PatternTest(input: "match x:\n    case [y, *_] if _:\n        return _", output: '_')]
#[PatternTest(input: "if x:\n    case _:", output: null)]
#[PatternTest(input: "# Special case\n_: int = 1", output: null)]
#[PatternTest(input: "case = {}\ncase[_]: int = 1", output: null)]
#[PatternTest(input: 'case | _ if x else lambda: 0', output: null)]
#[PatternTest(input: "match x:\n    case 0:\n        y = (\n0\n        )\n    case _:\n        pass", output: '_')]
#[PatternTest(input: "match x:\n    case Point(_\n=value):\n        pass", output: null)]
#[PatternTest(input: "x = f\"{\"(\"}\"\nmatch x:\n    case _:\n        pass", output: '_')]
#[RequiresSubstring('case')]
final readonly class PyCaseWildcardPattern implements Pattern
{
    /**
     * Finds `_` wildcards in the patterns of `case` clauses whose enclosing block is a `match` statement (or the snippet's top level).
     */
    public function match(string $content): array
    {
        if (! preg_match('/(?:^|(?<=[\r\n]))[ \t\f]*+case(?![\w\x80-\xff])/', $content)) {
            return ['match' => []];
        }

        $spans = $this->continuations($content);
        $span = $spans->current();
        $wildcards = [];
        $blocks = [];
        $scannedUntil = 0;
        $length = strlen($content);
        $lineStart = 0;

        while ($lineStart < $length) {
            while ($span !== null && $span[1] < $lineStart) {
                $spans->next();
                $span = $spans->current();
            }

            $continued = ($span[0] ?? $lineStart) < $lineStart;

            // Lines that continue an earlier statement, header or string are not statements and do not affect indentation
            if ($continued || $lineStart < $scannedUntil) {
                $lineStart = $this->nextLine($content, max($scannedUntil, $continued ? $span[1] : 0));

                continue;
            }

            $indentLength = strspn($content, " \t\f", $lineStart);
            $first = $lineStart + $indentLength;

            if (str_contains("#\r\n", $content[$first] ?? "\n")) {
                $lineStart = $this->nextLine($content, $first);

                continue;
            }

            $indent = $this->indentWidth(substr($content, $lineStart, $indentLength));

            while ($blocks !== [] && $blocks[array_key_last($blocks)][0] >= $indent) {
                array_pop($blocks);
            }

            $enclosedByMatch = $blocks === [] || $blocks[array_key_last($blocks)][1];
            $isMatchHeader = false;

            if (preg_match('/\G(?:match|case)(?![\w\x80-\xff])/', $content, $keyword, 0, $first)) {
                [$scannedUntil, $isStatement, $clauseWildcards] = $this->scan($content, $first + strlen($keyword[0]), $keyword[0] === 'case', $blocks === []);

                if ($keyword[0] === 'match') {
                    $isMatchHeader = $isStatement;
                } elseif ($isStatement && $enclosedByMatch) {
                    array_push($wildcards, ...$clauseWildcards);
                }
            }

            $blocks[] = [$indent, $isMatchHeader];
            $lineStart = $this->nextLine($content, max($first, $scannedUntil));
        }

        return ['match' => $wildcards];
    }

    public function getTokenType(): TokenTypeEnum
    {
        return TokenTypeEnum::KEYWORD;
    }

    /**
     * Scans a `match` or `case` header from just after its keyword up to its top-level colon.
     *
     * At the top level, a keyword followed by a bracket or operator could also start an expression such as `case[x]: int` or
     * `case | x if y else lambda: z`, so such a header only counts when its body starts on a later line.
     *
     * @return array{int, bool, array<int, array{string, int}>}
     */
    private function scan(string $content, int $index, bool $inPattern, bool $topLevel): array
    {
        $index = $this->skipInsignificant($content, $index, false);

        if (str_contains("=.:)]},\r\n", $content[$index] ?? "\n")) {
            return [$index, false, []];
        }

        $start = $index;
        $length = strlen($content);
        $depth = 0;
        $previous = '';
        $wildcards = [];

        while (($index = $this->skipInsignificant($content, $index, $depth > 0)) < $length) {
            $char = $content[$index];

            if ($char === "\n" || $char === "\r") {
                return [$index, false, []];
            }

            if ($char === ':' && $depth === 0) {
                $isStatement = ! $topLevel
                    || str_contains("#\r\n", $content[$index + 1 + strspn($content, " \t\f", $index + 1)] ?? "\n")
                    || preg_match('/\G(?:(?!(?:is|in|not|and|or|if)(?![\w\x80-\xff]))[\w\x80-\xff]|["\'{])/', $content, $patternStart, 0, $start) === 1;

                return [$index, $isStatement, $isStatement ? $wildcards : []];
            }

            if ($char === '"' || $char === "'") {
                $index = $this->skipString($content, $index);
                $previous = '"';

                continue;
            }

            if (preg_match('/\G[\w\x80-\xff]+/', $content, $word, 0, $index)) {
                $word = $word[0];
                $next = $index + strlen($word);

                if (($content[$next] ?? '') === '"' || ($content[$next] ?? '') === "'") {
                    if (preg_match('/^[rbuft]{1,2}$/i', $word)) {
                        $index = $this->skipString($content, $next, strtolower($word));
                        $previous = '"';

                        continue;
                    }
                }

                if ($word === 'if' && $depth === 0) {
                    $inPattern = false;
                } elseif ($inPattern && $word === '_' && $previous !== '.' && ! preg_match('/\G(?:=(?!=)|\.)/', $content, $nameOrAttribute, 0, $this->skipInsignificant($content, $next, $depth > 0))) {
                    $wildcards[] = ['_', $index];
                }

                $index = $next;
                $previous = 'a';

                continue;
            }

            // A pattern never assigns, calls, subscripts or reads attributes at the top level, so this is an ordinary statement using `case` as a name
            if ($inPattern && $depth === 0 && (
                ($char === '=' && $previous !== '=' && ($content[$index + 1] ?? '') !== '=')
                || (($char === '(' || $char === '[' || $char === '.') && in_array($previous, [')', ']', '}'], true))
            )) {
                return [$index, false, []];
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
            }

            $previous = $char;
            $index++;
        }

        return [$index, false, []];
    }

    /**
     * @return Generator<int, array{int, int}> start and end offsets of the strings, brackets and `\` line continuations that span several lines
     */
    private function continuations(string $content): Generator
    {
        $offset = 0;

        while (true) {
            $depth = 0;
            $start = 0;

            // Comments, single-line strings (f-strings only when their fields hold just simple strings), text without brackets and
            // short brackets nested up to three deep and closed on the same line are skipped in bulk; the bounds keep each attempt
            // within the PCRE backtracking limit
            while (preg_match(
                <<<'REGEX'
                /
                    (?(DEFINE)
                        (?<fquote> (?<=(?<![\w\x80-\xff])[fFtT]|(?<![\w\x80-\xff])[rR][fFtT]|(?<![\w\x80-\xff])[fFtT][rR])["'] )
                        (?<expression> [^"'\\{}\#\r\n]++ | "[^"\\{}\r\n]*+" | '[^'\\{}\r\n]*+' )
                        (?<field> \{ (?: (?&expression) | \{ (?&expression){0,16}+ \} ){0,16}+ \} )
                        (?<fsimple> (?=(?&fquote)) (?:
                            "(?!"")(?:[^"\\{}\r\n]++|\\[^{}\r\n]|\{\{|\}\}|(?&field)){0,64}+"
                          | '(?!'')(?:[^'\\{}\r\n]++|\\[^{}\r\n]|\{\{|\}\}|(?&field)){0,64}+'
                        ) )
                        (?<string> (?&fsimple) | (?!(?&fquote)) (?: "(?:[^"\\\r\n]++|\\[^\r\n])*+" | '(?:[^'\\\r\n]++|\\[^\r\n])*+' ) )
                        (?<flat> [(\[{](?:[^()\[\]{}"'\#\\\r\n]++|(?&string)){0,32}+[)\]}] )
                        (?<nested> [(\[{](?:[^()\[\]{}"'\#\\\r\n]++|(?&string)|(?&flat)){0,32}+[)\]}] )
                    )
                    (?: \#[^\r\n]*+
                      | [^()\[\]{}"'\#\\]++
                      | \\[^\r\n]
                      | (?&fsimple)
                      | (?!(?&fquote)) "(?!"")(?:[^"\\\r\n]++|\\[^\r\n])*+(?:"|(?=[\r\n]|\z))
                      | (?!(?&fquote)) '(?!'')(?:[^'\\\r\n]++|\\[^\r\n])*+(?:'|(?=[\r\n]|\z))
                      | [(\[{](?:[^()\[\]{}"'\#\\\r\n]++|(?&string)|(?&nested)){0,32}+[)\]}]
                    ) (*SKIP)(*FAIL)
                  | (?<formatted> (?&fquote) )
                  | [()\[\]{}]
                  | \\(?:\r\n?|\n)
                  | """(?:[^"\\]++|\\[\s\S]?|"(?!""))*+(?:"""|\z)
                  | '''(?:[^'\\]++|\\[\s\S]?|'(?!''))*+(?:'''|\z)
                  | "(?:[^"\\\r\n]++|\\[\s\S]?)*+"?
                  | '(?:[^'\\\r\n]++|\\[\s\S]?)*+'?
                /x
                REGEX,
                $content,
                $token,
                PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                $offset,
            )) {
                [$text, $index] = $token[0];

                // The replacement fields of f-strings and t-strings may nest further strings, so they need the string scanner
                $offset = $token['formatted'][0] === null ? $index + strlen($text) : $this->skipString($content, $index, $this->stringPrefix($content, $index));

                if (str_contains('([{', $text)) {
                    if ($depth++ === 0) {
                        $start = $index;
                    }
                } elseif (str_contains(')]}', $text)) {
                    if ($depth > 0 && --$depth === 0 && strcspn($content, "\r\n", $start, $offset - $start) < $offset - $start) {
                        yield [$start, $offset];
                    }
                } elseif ($depth === 0 && strcspn($content, "\r\n", $index, $offset - $index) < $offset - $index) {
                    yield [$index, $offset];
                }
            }

            // A bracket that never closes is an error rather than a continuation, so with one or two of them left open,
            // the text after the first is lexed again, which leaves one fewer open each time
            if ($depth === 0 || $depth > 2) {
                return;
            }

            $offset = $start + 1;
        }
    }

    /**
     * Skips a string literal, including the replacement fields of f-strings and t-strings, which may hold brackets,
     * comments, format specs and further strings, even with the same quotes.
     */
    private function skipString(string $content, int $index, string $prefix = ''): int
    {
        $start = $index;
        $frames = [$this->openString($content, $index, $prefix)];
        $index += strlen($frames[0][1]);

        // Python limits how deeply f-strings nest as well
        while ($frames !== [] && count($frames) < 32) {
            $top = array_key_last($frames);
            $frame = $frames[$top];

            if ($frame[0] === 'spec') {
                // A format spec ends with its replacement field, but may hold fields of its own
                $index += strcspn($content, '{}', $index);
                $char = $content[$index++] ?? '';

                if ($char === '{') {
                    $frames[] = ['field', 0];
                } elseif ($char === '}') {
                    array_pop($frames);
                } else {
                    break;
                }
            } elseif ($frame[0] === 'field') {
                $index += strcspn($content, "\"'#()[]{}:", $index);
                $char = $content[$index] ?? '';

                if ($char === '') {
                    break;
                } elseif ($char === '"' || $char === "'") {
                    $frames[] = $this->openString($content, $index, $this->stringPrefix($content, $index));
                    $index += strlen($frames[$top + 1][1]);
                } elseif ($char === '#') {
                    $index += strcspn($content, "\r\n", $index);
                } elseif ($frame[1] === 0 && $char === '}') {
                    array_pop($frames);
                    $index++;
                } elseif ($frame[1] === 0 && $char === ':') {
                    $frames[$top] = ['spec'];
                    $index++;
                } else {
                    $frames[$top][1] = max(0, $frame[1] + (str_contains('([{', $char) ? 1 : (str_contains(')]}', $char) ? -1 : 0)));
                    $index++;
                }
            } else {
                [, $delimiter, $formatted] = $frame;
                $index += strcspn($content, "\\\r\n" . $delimiter[0] . ($formatted ? '{}' : ''), $index);
                $char = $content[$index] ?? '';

                if ($char === '' || (strlen($delimiter) === 1 && ($char === "\r" || $char === "\n"))) {
                    break;
                } elseif ($char === '\\') {
                    // In f-strings, a brace after a backslash still opens or closes a replacement field
                    $index = $formatted && str_contains('{}', $content[$index + 1] ?? '\\') ? $index + 1 : $this->skipEscape($content, $index);
                } elseif ($char === '{' || $char === '}') {
                    if ($char === '{' && ($content[$index + 1] ?? '') !== '{') {
                        $frames[] = ['field', 0];
                    }

                    $index += ($content[$index + 1] ?? '') === $char ? 2 : 1;
                } elseif (substr($content, $index, strlen($delimiter)) === $delimiter) {
                    array_pop($frames);
                    $index += strlen($delimiter);
                } else {
                    $index++;
                }
            }
        }

        if ($frames !== [] && strlen($frames[0][1]) === 1) {
            $lineEnd = $start + strcspn($content, "\r\n", $start);

            // An unterminated single-quoted string ends at its line break, unless nesting too deeply stopped the scan later
            return count($frames) < 32 ? $lineEnd : max($lineEnd, $index);
        }

        return min($index, strlen($content));
    }

    /**
     * @return array{string, string, bool} a string frame with its delimiter and whether it has replacement fields
     */
    private function openString(string $content, int $index, string $prefix): array
    {
        $quote = $content[$index];
        $triple = $quote . $quote . $quote;

        return ['string', substr($content, $index, 3) === $triple ? $triple : $quote, strpbrk($prefix, 'ft') !== false];
    }

    /**
     * Returns the lowercase prefix letters of the string starting at the given quote.
     */
    private function stringPrefix(string $content, int $quote): string
    {
        return preg_match('/(?<![\w\x80-\xff])[rRbBuUfFtT]{1,2}\z/', substr($content, max(0, $quote - 3), min(3, $quote)), $prefix) ? strtolower($prefix[0]) : '';
    }

    /**
     * Skips spaces, comments and `\` line continuations, and also line breaks inside brackets.
     */
    private function skipInsignificant(string $content, int $index, bool $insideBrackets): int
    {
        $blank = $insideBrackets ? " \t\f\r\n" : " \t\f";

        while (true) {
            $index += strspn($content, $blank, $index);
            $char = $content[$index] ?? '';

            if ($char === '#') {
                $index += strcspn($content, "\r\n", $index);
            } elseif ($char === '\\' && str_contains("\r\n", $content[$index + 1] ?? '\\')) {
                $index = $this->skipEscape($content, $index);
            } else {
                return $index;
            }
        }
    }

    private function nextLine(string $content, int $offset): int
    {
        $offset += strcspn($content, "\r\n", $offset);

        return $offset + (substr($content, $offset, 2) === "\r\n" ? 2 : 1);
    }

    private function skipEscape(string $content, int $index): int
    {
        return $index + (substr_compare($content, "\\\r\n", $index, 3) === 0 ? 3 : 2);
    }

    private function indentWidth(string $indentation): int
    {
        $width = 0;

        for ($i = 0, $length = strlen($indentation); $i < $length; $i++) {
            $width = match ($indentation[$i]) {
                "\t" => intdiv($width, 8) * 8 + 8,
                "\f" => 0,
                default => $width + 1,
            };
        }

        return $width;
    }
}
