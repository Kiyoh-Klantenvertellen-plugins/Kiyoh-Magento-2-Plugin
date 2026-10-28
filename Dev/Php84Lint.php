<?php

namespace Kiyoh\Reviews\Dev;

/**
 * Fails the build when module PHP would break Magento setup:upgrade on PHP 8.4.
 *
 * Magento developer mode turns E_DEPRECATED into an exception ("Deprecated Functionality: ...").
 * Implicitly nullable parameters are deprecated while the file is compiled, which happens when
 * setup:upgrade loads console commands. php -l on PHP 8.4 prints that deprecation and still
 * exits 0, so this lint treats the warning as a failure. The token scan catches the same
 * parameter pattern on older PHP, plus PHP 8.4 deprecations that only fire when called.
 */
final class Php84Lint
{
    /**
     * Global functions deprecated on PHP 8.4, or still deprecated and fatal under Magento's handler.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_FUNCTIONS = [
        'lcg_value' => 'lcg_value() is deprecated in PHP 8.4',
        'xml_set_object' => 'xml_set_object() is deprecated in PHP 8.4',
        'mysqli_ping' => 'mysqli_ping() is deprecated in PHP 8.4',
        'mysqli_kill' => 'mysqli_kill() is deprecated in PHP 8.4',
        'mysqli_refresh' => 'mysqli_refresh() is deprecated in PHP 8.4',
        'utf8_encode' => 'utf8_encode() is deprecated and still emitted on PHP 8.4',
        'utf8_decode' => 'utf8_decode() is deprecated and still emitted on PHP 8.4',
        'strftime' => 'strftime() is deprecated and still emitted on PHP 8.4',
        'gmstrftime' => 'gmstrftime() is deprecated and still emitted on PHP 8.4',
    ];

    /**
     * @var array<string, string>
     */
    private const FORBIDDEN_CONSTANTS = [
        'E_STRICT' => 'E_STRICT is deprecated in PHP 8.4',
        'CURLOPT_BINARYTRANSFER' => 'CURLOPT_BINARYTRANSFER is deprecated in PHP 8.4',
        'SUNFUNCS_RET_TIMESTAMP' => 'SUNFUNCS_RET_TIMESTAMP is deprecated in PHP 8.4',
        'SUNFUNCS_RET_STRING' => 'SUNFUNCS_RET_STRING is deprecated in PHP 8.4',
        'SUNFUNCS_RET_DOUBLE' => 'SUNFUNCS_RET_DOUBLE is deprecated in PHP 8.4',
        'DOM_PHP_ERR' => 'DOM_PHP_ERR is deprecated in PHP 8.4',
        'MYSQLI_STORE_RESULT_COPY_DATA' => 'MYSQLI_STORE_RESULT_COPY_DATA is deprecated in PHP 8.4',
    ];

    /**
     * @return list<string>
     */
    public static function scan(string $root): array
    {
        $findings = [];
        foreach (self::files($root) as $file) {
            foreach (self::scanFile($file) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public static function scanFile(string $file): array
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return [$file . ': unreadable'];
        }

        $findings = self::scanSource($code, $file);

        return array_merge($findings, self::phpLintFindings($file, $findings));
    }

    /**
     * @return list<string>
     */
    public static function scanSource(string $code, string $file): array
    {
        $tokens = token_get_all($code);
        $findings = [];
        foreach (self::implicitNullableParameters($tokens) as $parameter) {
            $owner = $parameter['class'] === '' ? '' : $parameter['class'] . '::';
            $findings[] = sprintf(
                '%s:%d %s%s(): Implicitly marking parameter %s as nullable is deprecated',
                $file,
                $parameter['line'],
                $owner,
                $parameter['function'],
                $parameter['variable']
            );
        }
        foreach (self::otherDeprecations($tokens) as $deprecation) {
            $findings[] = sprintf('%s:%d %s', $file, $deprecation['line'], $deprecation['message']);
        }

        return $findings;
    }

    /**
     * @param list<string> $already
     * @return list<string>
     */
    public static function phpLintFindings(string $file, array $already = []): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }

        [$exitCode, $output] = self::runPhpLint($file);
        $findings = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim($line);
            if ($line === '' || strncmp($line, 'No syntax errors detected', 26) === 0) {
                continue;
            }
            if (!preg_match('/Deprecated|Parse error|Fatal error|Errors parsing/i', $line)) {
                continue;
            }
            if (self::coveredByTokenScan($already, $line)) {
                continue;
            }
            $findings[] = $line;
        }

        if ($exitCode !== 0 && $findings === []) {
            $findings[] = trim($output) !== '' ? trim($output) : $file . ': php -l exited ' . $exitCode;
        }

        return $findings;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<array{class: string, function: string, variable: string, line: int}>
     */
    private static function implicitNullableParameters(array $tokens): array
    {
        $found = [];
        $count = count($tokens);
        $class = '';
        $braceDepth = 0;
        $classDepth = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{') {
                $braceDepth++;
                continue;
            }
            if ($token === '}') {
                $braceDepth--;
                if ($classDepth !== null && $braceDepth < $classDepth) {
                    $class = '';
                    $classDepth = null;
                }
                continue;
            }
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $nameIndex = self::nextMeaningful($tokens, $i + 1);
                if ($nameIndex !== null && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_STRING) {
                    $class = $tokens[$nameIndex][1];
                    $classDepth = $braceDepth + 1;
                }
                continue;
            }

            if ($token[0] !== T_FUNCTION && $token[0] !== T_FN) {
                continue;
            }

            $function = '{closure}';
            $cursor = $i + 1;
            if ($token[0] === T_FUNCTION) {
                $cursor = self::skipIgnorable($tokens, $cursor);
                if (isset($tokens[$cursor]) && (self::text($tokens[$cursor]) === '&')) {
                    $cursor++;
                }
                $cursor = self::skipIgnorable($tokens, $cursor);
                if (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                    $function = $tokens[$cursor][1];
                    $cursor++;
                }
            }

            $cursor = self::skipIgnorable($tokens, $cursor);
            if (!isset($tokens[$cursor]) || $tokens[$cursor] !== '(') {
                continue;
            }

            foreach (self::parameters($tokens, $cursor) as $parameter) {
                if (!$parameter['implicit']) {
                    continue;
                }
                $found[] = [
                    'class' => $class,
                    'function' => $function,
                    'variable' => $parameter['variable'],
                    'line' => $token[2],
                ];
            }
        }

        return $found;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<array{implicit: bool, variable: string}>
     */
    private static function parameters(array $tokens, int $openParen): array
    {
        $parameters = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $openParen; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $i = self::endOfAttribute($tokens, $i);
                continue;
            }
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                if ($depth > 1) {
                    $current[] = $token;
                }
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    $parameters[] = self::analyzeParameter($current);
                    break;
                }
                $current[] = $token;
                continue;
            }
            if ($token === ',' && $depth === 1) {
                $parameters[] = self::analyzeParameter($current);
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        return array_values(array_filter($parameters, static function (array $parameter): bool {
            return $parameter['variable'] !== '';
        }));
    }

    /**
     * @param list<mixed> $tokens
     * @return array{implicit: bool, variable: string}
     */
    private static function analyzeParameter(array $tokens): array
    {
        $tokens = self::stripAttributes($tokens);
        $equals = self::topLevelEquals($tokens);
        $left = $equals === null ? $tokens : array_slice($tokens, 0, $equals);
        $default = $equals === null ? [] : array_slice($tokens, $equals + 1);
        $left = self::stripVisibility($left);

        $variableIndex = null;
        foreach ($left as $index => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $variableIndex = $index;
            }
        }
        if ($variableIndex === null) {
            return ['implicit' => false, 'variable' => ''];
        }

        $variable = $left[$variableIndex][1];
        $type = [];
        foreach (array_slice($left, 0, $variableIndex) as $token) {
            if (self::isIgnorable($token) || self::text($token) === '&' || self::isEllipsis($token)) {
                continue;
            }
            $type[] = $token;
        }

        return [
            'implicit' => $type !== [] && !self::typeIncludesNull($type) && self::defaultIsNull($default),
            'variable' => $variable,
        ];
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function typeIncludesNull(array $tokens): bool
    {
        $tokens = self::meaningful($tokens);
        if ($tokens === []) {
            return false;
        }
        if ($tokens[0] === '?') {
            return true;
        }

        $members = [];
        $current = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            }
            if ($token === '|' && $depth === 0) {
                $members[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        $members[] = $current;

        foreach ($members as $member) {
            $member = self::meaningful($member);
            if (count($member) !== 1 || !is_array($member[0]) || $member[0][0] !== T_STRING) {
                continue;
            }
            $name = strtolower($member[0][1]);
            if ($name === 'null' || $name === 'mixed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function defaultIsNull(array $tokens): bool
    {
        $tokens = self::meaningful($tokens);
        if (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_STRING) {
            return strcasecmp($tokens[0][1], 'null') === 0;
        }
        if (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_NAME_FULLY_QUALIFIED) {
            return strcasecmp($tokens[0][1], '\\null') === 0;
        }

        return false;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<mixed>
     */
    private static function stripAttributes(array $tokens): array
    {
        $stripped = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_ATTRIBUTE) {
                $stripped[] = $tokens[$i];
                continue;
            }
            $i = self::endOfAttribute($tokens, $i);
        }

        return $stripped;
    }

    /**
     * T_ATTRIBUTE's text is "#[". Return the index of the closing "]".
     *
     * @param list<mixed> $tokens
     */
    private static function endOfAttribute(array $tokens, int $index): int
    {
        $depth = 1;
        $count = count($tokens);
        $index++;
        while ($index < $count && $depth > 0) {
            $token = $tokens[$index];
            if ($token === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $depth++;
            } elseif ($token === ']') {
                $depth--;
            }
            if ($depth === 0) {
                return $index;
            }
            $index++;
        }

        return $index;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<mixed>
     */
    private static function stripVisibility(array $tokens): array
    {
        $visibility = [T_PUBLIC => true, T_PROTECTED => true, T_PRIVATE => true];
        if (defined('T_READONLY')) {
            $visibility[T_READONLY] = true;
        }

        $index = 0;
        $count = count($tokens);
        while ($index < $count) {
            while ($index < $count && self::isIgnorable($tokens[$index])) {
                $index++;
            }
            if ($index >= $count || !is_array($tokens[$index]) || !isset($visibility[$tokens[$index][0]])) {
                break;
            }
            $index++;
            $next = $index;
            while ($next < $count && self::isIgnorable($tokens[$next])) {
                $next++;
            }
            if ($next < $count && $tokens[$next] === '(') {
                $depth = 1;
                $next++;
                while ($next < $count && $depth > 0) {
                    if ($tokens[$next] === '(') {
                        $depth++;
                    } elseif ($tokens[$next] === ')') {
                        $depth--;
                    }
                    $next++;
                }
                $index = $next;
            }
        }

        return array_slice($tokens, $index);
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function topLevelEquals(array $tokens): ?int
    {
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                continue;
            }
            if ($token === '=' && $depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<array{line: int, message: string}>
     */
    private static function otherDeprecations(array $tokens): array
    {
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                $nameIndex = self::nextMeaningful($tokens, $i + 1);
                if (
                    $nameIndex !== null
                    && is_array($tokens[$nameIndex])
                    && $tokens[$nameIndex][0] === T_STRING
                    && $tokens[$nameIndex][1] === '_'
                ) {
                    $found[] = [
                        'line' => $token[2],
                        'message' => 'Using "_" as a class name is deprecated in PHP 8.4',
                    ];
                }
                continue;
            }

            $name = self::callableName($token);
            if ($name === null) {
                if ($token[0] === T_STRING) {
                    $constant = self::constantDeprecation($tokens, $i);
                    if ($constant !== null) {
                        $found[] = ['line' => $token[2], 'message' => $constant];
                    }
                }
                continue;
            }

            $next = self::nextMeaningful($tokens, $i + 1);
            if ($next === null || $tokens[$next] !== '(') {
                if ($token[0] === T_STRING) {
                    $constant = self::constantDeprecation($tokens, $i);
                    if ($constant !== null) {
                        $found[] = ['line' => $token[2], 'message' => $constant];
                    }
                }
                continue;
            }

            $previous = self::previousMeaningful($tokens, $i - 1);
            $previousId = is_array($previous) ? $previous[0] : null;
            $isMethod = $previousId === T_OBJECT_OPERATOR
                || $previousId === T_DOUBLE_COLON
                || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $previousId === T_NULLSAFE_OBJECT_OPERATOR);
            $isDefinition = $previousId === T_FUNCTION;

            if (!$isMethod && !$isDefinition && isset(self::FORBIDDEN_FUNCTIONS[$name])) {
                $found[] = ['line' => $token[2], 'message' => self::FORBIDDEN_FUNCTIONS[$name]];
                continue;
            }

            if ($isDefinition) {
                continue;
            }

            $argumentCount = self::argumentCount($tokens, $next);
            if (!$isMethod && $name === 'trigger_error' && self::argumentsContain($tokens, $next, 'E_USER_ERROR')) {
                $found[] = [
                    'line' => $token[2],
                    'message' => 'Passing E_USER_ERROR to trigger_error() is deprecated in PHP 8.4',
                ];
            }
            if (!$isMethod && $name === 'session_set_save_handler' && $argumentCount !== null && $argumentCount > 2) {
                $found[] = [
                    'line' => $token[2],
                    'message' => 'session_set_save_handler() with more than 2 arguments is deprecated in PHP 8.4',
                ];
            }

            $minimum = self::csvEscapeMinimum($name, $isMethod);
            if ($minimum !== null && $argumentCount !== null && $argumentCount < $minimum) {
                $found[] = [
                    'line' => $token[2],
                    'message' => $name . '() must pass the $escape argument explicitly on PHP 8.4 (got ' . $argumentCount . ')',
                ];
            }
        }

        return $found;
    }

    private static function csvEscapeMinimum(string $name, bool $isMethod): ?int
    {
        if (!$isMethod && ($name === 'str_getcsv')) {
            return 4;
        }
        if (!$isMethod && ($name === 'fgetcsv' || $name === 'fputcsv')) {
            return 5;
        }
        if ($isMethod && ($name === 'fgetcsv' || $name === 'fputcsv')) {
            return 4;
        }
        if ($isMethod && $name === 'setcsvcontrol') {
            return 3;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function constantDeprecation(array $tokens, int $index): ?string
    {
        $previous = self::previousMeaningful($tokens, $index - 1);
        $previousId = is_array($previous) ? $previous[0] : null;
        if (in_array($previousId, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_NEW], true)) {
            return null;
        }
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $previousId === T_NULLSAFE_OBJECT_OPERATOR) {
            return null;
        }

        $name = $tokens[$index][1];
        if (isset(self::FORBIDDEN_CONSTANTS[$name])) {
            return self::FORBIDDEN_CONSTANTS[$name];
        }
        if (strncmp($name, 'MYSQLI_REFRESH_', 15) === 0) {
            return $name . ' is deprecated in PHP 8.4';
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function callableName(array $token): ?string
    {
        if ($token[0] === T_STRING) {
            return strtolower($token[1]);
        }
        if ($token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NAME_QUALIFIED) {
            $name = strtolower($token[1]);
            $slash = strrpos($name, '\\');

            return $slash === false ? $name : substr($name, $slash + 1);
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function argumentCount(array $tokens, int $openParen): ?int
    {
        $depth = 0;
        $count = count($tokens);
        $arguments = 0;
        $saw = false;
        for ($i = $openParen; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $saw ? $arguments + 1 : 0;
                }
            } elseif ($token === ',' && $depth === 1) {
                $arguments++;
                $saw = false;
                continue;
            }
            if ($depth === 1 && self::isEllipsis($token)) {
                return null;
            }
            if ($depth === 1 && !self::isIgnorable($token)) {
                $saw = true;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function argumentsContain(array $tokens, int $openParen, string $name): bool
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $openParen; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
                continue;
            }
            if ($depth === 1 && is_array($token) && $token[0] === T_STRING && strcasecmp($token[1], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $already
     */
    private static function coveredByTokenScan(array $already, string $lintLine): bool
    {
        if (!preg_match('/Implicitly marking parameter (\$\w+)/', $lintLine, $matches)) {
            return false;
        }
        foreach ($already as $finding) {
            if (strpos($finding, $matches[1]) !== false && strpos($finding, 'Implicitly marking parameter') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function runPhpLint(string $file): array
    {
        $command = [
            PHP_BINARY,
            '-d',
            'error_reporting=E_ALL',
            '-d',
            'display_errors=1',
            '-d',
            'log_errors=0',
            '-l',
            $file,
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return [1, $file . ': unable to run php -l'];
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if ($extension !== 'php' && $extension !== 'phtml') {
                continue;
            }
            $path = $file->getPathname();
            if (self::excluded($root, $path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    private static function excluded(string $root, string $path): bool
    {
        $relative = self::relative($root, $path);
        foreach (['vendor/', '.git/', 'Test/coverage/'] as $prefix) {
            if (strncmp($relative, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $root, string $path): string
    {
        $root = rtrim($root, '/') . '/';
        if (strncmp($path, $root, strlen($root)) === 0) {
            return substr($path, strlen($root));
        }

        return $path;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<mixed>
     */
    private static function meaningful(array $tokens): array
    {
        $meaningful = [];
        foreach ($tokens as $token) {
            if (!self::isIgnorable($token)) {
                $meaningful[] = $token;
            }
        }

        return $meaningful;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function nextMeaningful(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        while ($index < $count) {
            if (!self::isIgnorable($tokens[$index])) {
                return $index;
            }
            $index++;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return mixed
     */
    private static function previousMeaningful(array $tokens, int $index)
    {
        while ($index >= 0) {
            if (!self::isIgnorable($tokens[$index])) {
                return $tokens[$index];
            }
            $index--;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function skipIgnorable(array $tokens, int $index): int
    {
        $count = count($tokens);
        while ($index < $count && self::isIgnorable($tokens[$index])) {
            $index++;
        }

        return $index;
    }

    /**
     * @param mixed $token
     */
    private static function isIgnorable($token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param mixed $token
     */
    private static function isEllipsis($token): bool
    {
        return is_array($token) && defined('T_ELLIPSIS') && $token[0] === T_ELLIPSIS;
    }

    /**
     * @param mixed $token
     */
    private static function text($token): string
    {
        if (is_array($token)) {
            return $token[1];
        }

        return (string) $token;
    }
}
