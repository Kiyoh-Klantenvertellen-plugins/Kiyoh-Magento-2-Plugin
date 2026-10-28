<?php

namespace Kiyoh\Reviews\Test\Unit\Php84;

use Kiyoh\Reviews\Dev\Php84Lint;
use PHPUnit\Framework\TestCase;

class Php84LintTest extends TestCase
{
    /**
     * @dataProvider implicitNullableProvider
     */
    public function testImplicitNullableDetection(string $parameter, bool $deprecated): void
    {
        $code = "<?php\nclass CheckConfigCommand {\n    public function __construct($parameter) {}\n}\n";
        $findings = Php84Lint::scanSource($code, 'CheckConfigCommand.php');

        if ($deprecated) {
            $this->assertNotSame([], $findings, $parameter);
            $this->assertStringContainsString('Implicitly marking parameter', $findings[0]);
        } else {
            $this->assertSame([], $findings, $parameter);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function implicitNullableProvider(): array
    {
        return [
            'string default null' => ['string $name = null', true],
            'nullable prefix' => ['?string $name = null', false],
            'union null' => ['string|null $name = null', false],
            'null first in union' => ['null|string $name = null', false],
            'mixed includes null' => ['mixed $name = null', false],
            'untyped' => ['$name = null', false],
            'callable' => ['callable $cb = null', true],
            'nullable callable' => ['?callable $cb = null', false],
            'namespaced class' => ['\\Foo\\Bar $x = null', true],
            'nullable namespaced class' => ['?\\Foo\\Bar $x = null', false],
            'intersection' => ['Foo&Bar $x = null', true],
            'dnf with null' => ['(Foo&Bar)|null $x = null', false],
            'uppercase NULL' => ['string $name = NULL', true],
            'attribute' => ['#[\\SensitiveParameter] string $name = null', true],
            'by reference' => ['string &$name = null', true],
            'string default' => ['string $name = "null"', false],
            'second parameter nullable' => ['string $a, ?string $b = null', false],
            'promoted nullable' => ['private ?string $name = null', false],
            'readonly promoted nullable' => ['private readonly ?string $name = null', false],
        ];
    }

    public function testMultilineCommandConstructorMatchesTheReportedSignature(): void
    {
        $code = <<<'PHP'
<?php
namespace Kiyoh\Reviews\Console\Command;
class CheckConfigCommand {
    public function __construct(
        State $appState,
        string $name = null
    ) {}
}
PHP;
        $findings = Php84Lint::scanSource($code, 'Console/Command/CheckConfigCommand.php');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('CheckConfigCommand::__construct()', $findings[0]);
        $this->assertStringContainsString('$name', $findings[0]);
    }

    public function testFixedSignatureIsClean(): void
    {
        $code = <<<'PHP'
<?php
class CheckConfigCommand {
    public function __construct(
        State $appState,
        ?string $name = null
    ) {}
}
PHP;

        $this->assertSame([], Php84Lint::scanSource($code, 'CheckConfigCommand.php'));
    }

    public function testArrowAndClosureParameters(): void
    {
        $this->assertNotSame(
            [],
            Php84Lint::scanSource("<?php\n\$fn = fn(string \$x = null) => \$x;\n", 'arrow.php')
        );
        $this->assertSame(
            [],
            Php84Lint::scanSource("<?php\n\$fn = fn(\$x = null) => \$x;\n", 'arrow-untyped.php')
        );
        $this->assertNotSame(
            [],
            Php84Lint::scanSource("<?php\n\$fn = function (string \$name = null) {};\n", 'closure.php')
        );
    }

    public function testOtherPhp84Deprecations(): void
    {
        $cases = [
            "<?php\nlcg_value();\n" => 'lcg_value()',
            "<?php\n\\lcg_value();\n" => 'lcg_value()',
            "<?php\necho E_STRICT;\n" => 'E_STRICT',
            "<?php\ntrigger_error('x', E_USER_ERROR);\n" => 'E_USER_ERROR',
            "<?php\nstr_getcsv(\$line);\n" => '$escape',
            "<?php\nsession_set_save_handler(\$open, \$close, \$read, \$write, \$destroy, \$gc);\n" => 'session_set_save_handler()',
            "<?php\nclass _ {}\n" => '"_"',
            "<?php\nxml_set_object(\$parser, \$this);\n" => 'xml_set_object()',
        ];
        foreach ($cases as $code => $needle) {
            $findings = Php84Lint::scanSource($code, 'sample.php');
            $this->assertNotSame([], $findings, $code);
            $this->assertStringContainsString($needle, $findings[0]);
        }
    }

    public function testAllowedCallShapes(): void
    {
        $clean = [
            "<?php\nclass C { public function lcg_value() {} }\n",
            "<?php\n\$a->lcg_value();\n",
            "<?php\nC::lcg_value();\n",
            "<?php\ntrigger_error('x');\n",
            "<?php\nstr_getcsv(\$line, ',', '\"', '');\n",
            "<?php\nfgetcsv(\$handle, 0, ',', '\"', '');\n",
            "<?php\nclass C { public function f() { return \$this->file->fgetcsv(0, ',', '\"', ''); } }\n",
            "<?php\necho E_NOTICE;\n",
        ];
        foreach ($clean as $code) {
            $this->assertSame([], Php84Lint::scanSource($code, 'clean.php'), $code);
        }
    }

    public function testPhpLintAgreesOnPhp84(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('php -l deprecation output is PHP 8.4+');
        }

        $directory = sys_get_temp_dir() . '/kiyoh-php84-lint-' . getmypid();
        if (!is_dir($directory)) {
            mkdir($directory);
        }

        $index = 0;
        foreach ($this->implicitNullableProvider() as $label => $case) {
            [$parameter, $deprecated] = $case;
            $path = $directory . '/case-' . $index . '.php';
            $index++;
            file_put_contents($path, "<?php\nclass C {\n    public function __construct($parameter) {}\n}\n");
            $lint = Php84Lint::phpLintFindings($path);
            $deprecatedLines = array_filter($lint, static function (string $line): bool {
                return strpos($line, 'Deprecated') !== false;
            });
            if ($deprecated) {
                $this->assertNotSame([], $deprecatedLines, $label);
            } else {
                $this->assertSame([], $lint, $label . ' ' . implode(' | ', $lint));
            }
        }
    }

    public function testModuleSourcesAreCleanOnPhp84(): void
    {
        $findings = Php84Lint::scan(dirname(__DIR__, 3));
        $this->assertSame([], $findings, implode("\n", $findings));
    }
}
