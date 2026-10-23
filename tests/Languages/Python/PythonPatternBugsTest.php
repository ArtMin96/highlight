<?php

declare(strict_types=1);

namespace Tempest\Highlight\Tests\Languages\Python;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Languages\Python\Patterns\PyCaseWildcardPattern;

final class PythonPatternBugsTest extends TestCase
{
    #[DataProvider('provide_highlight_cases')]
    public function test_highlight(string $content, string $expected): void
    {
        $highlighter = new Highlighter();

        $this->assertSame($expected, $highlighter->parse($content, 'python'));
    }

    #[DataProvider('provide_wildcard_after_long_continuation_cases')]
    public function test_wildcard_after_long_continuation(string $continuation): void
    {
        $content = "match x:\n    case 0:\n        y = " . $continuation . "\n    case _:\n        pass";

        $this->assertSame([['_', strlen($content) - strlen("_:\n        pass")]], new PyCaseWildcardPattern()->match($content)['match']);
        $this->assertSame(PREG_NO_ERROR, preg_last_error(), preg_last_error_msg());
    }

    public static function provide_wildcard_after_long_continuation_cases(): iterable
    {
        yield 'nested brackets' => ['(' . str_repeat("\n(", 50_000) . str_repeat(')', 50_001)];
        yield 'backslashes' => [str_repeat("1 + \\\n", 50_000) . '1'];
        yield 'string in brackets' => ['f("""' . str_repeat("\n)", 50_000) . '""")'];
        yield 'f-string fields' => ['f"""' . str_repeat("{\")\"}\n", 50_000) . '"""'];
        yield 'long bracket line' => ['[' . str_repeat('[(a)], ', 100_000) . "\n]"];
    }

    public static function provide_highlight_cases(): iterable
    {
        yield 'and keyword' => [
            'a and b',
            'a <span class="hl-keyword">and</span> b',
        ];

        yield 'wildcard case' => [
            'case _:',
            'case <span class="hl-keyword">_</span>:',
        ];

        yield 'wildcard case in match block' => [
            "match x:\n    case _:\n        pass",
            "match x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore parameter' => [
            'def consume(_: int): pass',
            '<span class="hl-keyword">def</span> <span class="hl-property">consume</span>(_: <span class="hl-type">int</span>): <span class="hl-keyword">pass</span>',
        ];

        yield 'underscore annotated variable' => [
            '_: int = 1',
            '_: <span class="hl-type">int</span> <span class="hl-operator">=</span> <span class="hl-number">1</span>',
        ];

        yield 'underscore dictionary key' => [
            'mapping = {_: "translated"}',
            'mapping <span class="hl-operator">=</span> {_: <span class="hl-value">&quot;translated&quot;</span>}',
        ];

        yield 'underscore lambda parameter' => [
            'lambda _: 0',
            '<span class="hl-keyword">lambda</span> _: <span class="hl-number">0</span>',
        ];

        yield 'wildcard case after two spaces' => [
            "match value:\n    case  _:\n        pass",
            "match value:\n    case  <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard case after space and tab' => [
            "match value:\n    case \t_:\n        pass",
            "match value:\n    case \t<span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore after comment ending in case' => [
            "# Special case\n_: int = 1",
            "<span class=\"hl-comment\"># Special case</span>\n_: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'wildcard in or pattern' => [
            "match x:\n    case 1 | _:\n        pass",
            "match x:\n    case <span class=\"hl-number\">1</span> <span class=\"hl-operator\">|</span> <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard in sequence pattern' => [
            "match x:\n    case (_, x):\n        pass",
            "match x:\n    case (<span class=\"hl-keyword\">_</span>, x):\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard star pattern' => [
            "match x:\n    case [x, *_]:\n        pass",
            "match x:\n    case [x, <span class=\"hl-operator\">*</span><span class=\"hl-keyword\">_</span>]:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard in class pattern' => [
            "match x:\n    case Point(x=_):\n        pass",
            "match x:\n    case Point(<span class=\"hl-variable\">x</span><span class=\"hl-operator\">=</span><span class=\"hl-keyword\">_</span>):\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard in mapping pattern' => [
            "match x:\n    case {\"k\": _}:\n        pass",
            "match x:\n    case {<span class=\"hl-value\">&quot;k&quot;</span>: <span class=\"hl-keyword\">_</span>}:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore in guard' => [
            "match x:\n    case _ if _ > 0:\n        pass",
            "match x:\n    case <span class=\"hl-keyword\">_</span> <span class=\"hl-keyword\">if</span> _ <span class=\"hl-operator\">&gt;</span> <span class=\"hl-number\">0</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore in one-line case body' => [
            "match x:\n    case _: return _",
            "match x:\n    case <span class=\"hl-keyword\">_</span>: <span class=\"hl-keyword\">return</span> _",
        ];

        yield 'underscore in case string' => [
            "match x:\n    case \"_\":\n        pass",
            "match x:\n    case <span class=\"hl-value\">&quot;_&quot;</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore in case comment' => [
            "match x:\n    case _:  # _ comment\n        pass",
            "match x:\n    case <span class=\"hl-keyword\">_</span>:  <span class=\"hl-comment\"># _ comment</span>\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore value pattern attribute' => [
            "match x:\n    case Color._:\n        pass",
            "match x:\n    case Color._:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'case identifier with lambda' => [
            "case = lambda _: _",
            "case <span class=\"hl-operator\">=</span> <span class=\"hl-keyword\">lambda</span> _: _",
        ];

        yield 'case outside match block' => [
            "if x:\n    case _:\n        pass",
            "<span class=\"hl-keyword\">if</span> x:\n    case _:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'case subscript assigned a lambda' => [
            "case = {}\ncase[_] = lambda _: _",
            "case <span class=\"hl-operator\">=</span> {}\ncase[_] <span class=\"hl-operator\">=</span> <span class=\"hl-keyword\">lambda</span> _: _",
        ];

        yield 'case call subscript annotation' => [
            "case(_)[0]: int = 1",
            "case(_)[<span class=\"hl-number\">0</span>]: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'underscore attribute in mapping key' => [
            "match x:\n    case {_.y: _, 1: z}:\n        pass",
            "match x:\n    case {_.y: <span class=\"hl-keyword\">_</span>, <span class=\"hl-number\">1</span>: z}:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after multi-line match subject' => [
            "match (x,\ny):\n    case _:\n        pass",
            "match (x,\ny):\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after multi-line string in case body' => [
            "match x:\n    case 1:\n        s = \"\"\"\ndoc\n\"\"\"\n    case _:\n        pass",
            "match x:\n    case <span class=\"hl-number\">1</span>:\n        s <span class=\"hl-operator\">=</span> <span class=\"hl-value\">&quot;&quot;&quot;\ndoc\n&quot;&quot;&quot;</span>\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'case subscript annotation' => [
            "case = {}\n_: int = 0\ncase[_]: int = 1",
            "case <span class=\"hl-operator\">=</span> {}\n_: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">0</span>\ncase[_]: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'case subscript annotation with space' => [
            'case [_] : int = 1',
            'case [_] : <span class="hl-type">int</span> <span class="hl-operator">=</span> <span class="hl-number">1</span>',
        ];

        yield 'case subscript annotation continued' => [
            "case[_]: \\\n    int = 1",
            "case[_]: \\\n    <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'case expression with lambda' => [
            "case = {}\ncase[_] if x else lambda: _",
            "case <span class=\"hl-operator\">=</span> {}\ncase[_] <span class=\"hl-keyword\">if</span> x <span class=\"hl-keyword\">else</span> <span class=\"hl-keyword\">lambda</span>: _",
        ];

        yield 'case operator expression with lambda' => [
            'case | _ if x else lambda: 0',
            'case <span class="hl-operator">|</span> _ <span class="hl-keyword">if</span> x <span class="hl-keyword">else</span> <span class="hl-keyword">lambda</span>: <span class="hl-number">0</span>',
        ];

        yield 'case continued subscript annotation' => [
            "case = {}\ncase \\\n[_]: int = 1",
            "case <span class=\"hl-operator\">=</span> {}\ncase \\\n[_]: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'case call attribute annotation' => [
            "class C:\n    value = 0\ndef case(x): return C\ncase(_).value: int = 1",
            "<span class=\"hl-keyword\">class</span> <span class=\"hl-property\">C</span>:\n    value <span class=\"hl-operator\">=</span> <span class=\"hl-number\">0</span>\n<span class=\"hl-keyword\">def</span> <span class=\"hl-property\">case</span>(x): <span class=\"hl-keyword\">return</span> C\ncase(_).value: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'case subscript clause in match block' => [
            "match x:\n    case[_]: int = 1",
            "match x:\n    case[<span class=\"hl-keyword\">_</span>]: <span class=\"hl-type\">int</span> <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span>",
        ];

        yield 'one-line wildcard case in match block' => [
            "match x:\n    case _: pass",
            "match x:\n    case <span class=\"hl-keyword\">_</span>: <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'one-line wildcard case' => [
            'case _: return _',
            'case <span class="hl-keyword">_</span>: <span class="hl-keyword">return</span> _',
        ];

        yield 'list case with body on next line' => [
            "case [_]:\n    pass",
            "case [<span class=\"hl-keyword\">_</span>]:\n    <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'list case without wildcard' => [
            'case [x]:',
            'case [x]:',
        ];

        yield 'wildcard after column 0 bracket continuation' => [
            "match x:\n    case 0:\n        y = (\n0\n        )\n    case _:\n        pass",
            "match x:\n    case <span class=\"hl-number\">0</span>:\n        y <span class=\"hl-operator\">=</span> (\n<span class=\"hl-number\">0</span>\n        )\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after column 0 backslash continuation' => [
            "match x:\n    case 0:\n        y = 1 + \\\n2\n    case _:\n        pass",
            "match x:\n    case <span class=\"hl-number\">0</span>:\n        y <span class=\"hl-operator\">=</span> <span class=\"hl-number\">1</span> <span class=\"hl-operator\">+</span> \\\n<span class=\"hl-number\">2</span>\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after indented multi-line expression' => [
            "match x:\n    case 0:\n        y = f(\n            a,\n        )\n    case _:\n        pass",
            "match x:\n    case <span class=\"hl-number\">0</span>:\n        y <span class=\"hl-operator\">=</span> f(\n            a,\n        )\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore attribute across lines in mapping key' => [
            "match x:\n    case {\n        _\n        .key: _\n    }:\n        pass",
            "match x:\n    case {\n        _\n        .key: <span class=\"hl-keyword\">_</span>\n    }:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore attribute after comment in mapping key' => [
            "match x:\n    case {\n        _  # c\n        .key: _\n    }:\n        pass",
            "match x:\n    case {\n        _  <span class=\"hl-comment\"># c</span>\n        .key: <span class=\"hl-keyword\">_</span>\n    }:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore keyword across lines in class pattern' => [
            "match x:\n    case Point(_\n=value):\n        pass",
            "match x:\n    case Point(_\n<span class=\"hl-operator\">=</span>value):\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'underscore keyword after comment in class pattern' => [
            "match x:\n    case Point(_ # c\n= v):\n        pass",
            "match x:\n    case Point(_ <span class=\"hl-comment\"># c</span>\n<span class=\"hl-operator\">=</span> v):\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard in value or pattern' => [
            "match x:\n    case Color.RED | _:\n        pass",
            "match x:\n    case Color.RED <span class=\"hl-operator\">|</span> <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after f-string with nested bracket string' => [
            "x = f\"{\"(\"}\"\nmatch x:\n    case _:\n        pass",
            "x <span class=\"hl-operator\">=</span> f<span class=\"hl-value\">&quot;{&quot;</span>(<span class=\"hl-value\">&quot;}&quot;</span>\nmatch x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after f-string format spec field' => [
            "x = f\"{x:{\"(\"}}\"\nmatch x:\n    case _:\n        pass",
            "x <span class=\"hl-operator\">=</span> f<span class=\"hl-value\">&quot;{x:{&quot;</span>(<span class=\"hl-value\">&quot;}}&quot;</span>\nmatch x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after triple-quoted f-string field' => [
            "x = f'''{\n\"(\"\n}'''\nmatch x:\n    case 0:\n        y = (\n0)\n    case _:\n        pass",
            "x <span class=\"hl-operator\">=</span> f<span class=\"hl-value\">'''{\n&quot;(&quot;\n}'''</span>\nmatch x:\n    case <span class=\"hl-number\">0</span>:\n        y <span class=\"hl-operator\">=</span> (\n<span class=\"hl-number\">0</span>)\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after raw f-string' => [
            "x = rf\"{'('}\"\nmatch x:\n    case _:\n        pass",
            "x <span class=\"hl-operator\">=</span> rf<span class=\"hl-value\">&quot;{'('}&quot;</span>\nmatch x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after t-string with nested bracket string' => [
            "x = t\"{\"[\"}\"\nmatch x:\n    case _:\n        pass",
            "x <span class=\"hl-operator\">=</span> t<span class=\"hl-value\">&quot;{&quot;</span>[<span class=\"hl-value\">&quot;}&quot;</span>\nmatch x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after f-string in guard' => [
            "match x:\n    case _ if f\"{\"(\"}\":\n        pass\n    case [_]:\n        pass",
            "match x:\n    case <span class=\"hl-keyword\">_</span> <span class=\"hl-keyword\">if</span> f<span class=\"hl-value\">&quot;{&quot;</span>(<span class=\"hl-value\">&quot;}&quot;</span>:\n        <span class=\"hl-keyword\">pass</span>\n    case [<span class=\"hl-keyword\">_</span>]:\n        <span class=\"hl-keyword\">pass</span>",
        ];

        yield 'wildcard after unclosed bracket' => [
            "f(\nmatch x:\n    case _:\n        pass",
            "f(\nmatch x:\n    case <span class=\"hl-keyword\">_</span>:\n        <span class=\"hl-keyword\">pass</span>",
        ];
    }
}
