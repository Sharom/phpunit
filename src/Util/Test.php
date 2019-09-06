<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Util;

use PharIo\Version\VersionConstraintParser;
use PHPUnit\Annotation\DocBlock;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\CodeCoverageException;
use PHPUnit\Framework\InvalidCoversTargetException;
use PHPUnit\Framework\InvalidDataProviderException;
use PHPUnit\Framework\SelfDescribing;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Warning;
use PHPUnit\Runner\Version;
use SebastianBergmann\Environment\OperatingSystem;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Test
{
    /**
     * @var int
     */
    public const UNKNOWN = -1;

    /**
     * @var int
     */
    public const SMALL = 0;

    /**
     * @var int
     */
    public const MEDIUM = 1;

    /**
     * @var int
     */
    public const LARGE = 2;

    /**
     * @var string
     *
     * @todo This constant should be private (it's public because of TestTest::testGetProvidedDataRegEx)
     */
    public const REGEX_DATA_PROVIDER = '/@dataProvider\s+([a-zA-Z0-9._:-\\\\x7f-\xff]+)/';

    /**
     * @var array
     */
    private static $annotationCache = [];

    /**
     * @var array
     */
    private static $hookMethods = [];

    /**
     * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
     */
    public static function describe(\PHPUnit\Framework\Test $test): array
    {
        if ($test instanceof TestCase) {
            return [\get_class($test), $test->getName()];
        }

        if ($test instanceof SelfDescribing) {
            return ['', $test->toString()];
        }

        return ['', \get_class($test)];
    }

    public static function describeAsString(\PHPUnit\Framework\Test $test): string
    {
        if ($test instanceof SelfDescribing) {
            return $test->toString();
        }

        return \get_class($test);
    }

    /**
     * @throws CodeCoverageException
     *
     * @return array|bool
     */
    public static function getLinesToBeCovered(string $className, string $methodName)
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        if (!self::shouldCoversAnnotationBeUsed($annotations)) {
            return false;
        }

        return self::getLinesToBeCoveredOrUsed($className, $methodName, 'covers');
    }

    /**
     * Returns lines of code specified with the @uses annotation.
     *
     * @throws CodeCoverageException
     */
    public static function getLinesToBeUsed(string $className, string $methodName): array
    {
        return self::getLinesToBeCoveredOrUsed($className, $methodName, 'uses');
    }

    public static function requiresCodeCoverageDataCollection(TestCase $test): bool
    {
        $annotations = $test->getAnnotations();

        // If there is no @covers annotation but a @coversNothing annotation on
        // the test method then code coverage data does not need to be collected
        if (isset($annotations['method']['coversNothing'])) {
            return false;
        }

        // If there is at least one @covers annotation then
        // code coverage data needs to be collected
        if (isset($annotations['method']['covers'])) {
            return true;
        }

        // If there is no @covers annotation but a @coversNothing annotation
        // then code coverage data does not need to be collected
        if (isset($annotations['class']['coversNothing'])) {
            return false;
        }

        // If there is no @coversNothing annotation then
        // code coverage data may be collected
        return true;
    }

    /**
     * @throws Exception
     */
    public static function getRequirements(string $className, string $methodName): array
    {
        try {
            $reflector = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        try {
            $method = $reflector->getMethod($methodName);
        } catch (\ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return self::mergeThingsRecursivelyBecausePHPsArrayMergeRecursiveIsUselessBurningGarbage(
            DocBlock::ofClass($reflector)
                ->requirements(),
            DocBlock::ofFunction($method)
                ->requirements()
        );
    }

    /**
     * Returns the missing requirements for a test.
     *
     * @throws Warning
     */
    public static function getMissingRequirements(string $className, string $methodName): array
    {
        $required = static::getRequirements($className, $methodName);
        $missing  = [];
        $hint     = null;

        if (!empty($required['PHP'])) {
            $operator = empty($required['PHP']['operator']) ? '>=' : $required['PHP']['operator'];

            if (!\version_compare(\PHP_VERSION, $required['PHP']['version'], $operator)) {
                $missing[] = \sprintf('PHP %s %s is required.', $operator, $required['PHP']['version']);
                $hint      = $hint ?? 'PHP';
            }
        } elseif (!empty($required['PHP_constraint'])) {
            $version = new \PharIo\Version\Version(self::sanitizeVersionNumber(\PHP_VERSION));

            if (!$required['PHP_constraint']['constraint']->complies($version)) {
                $missing[] = \sprintf(
                    'PHP version does not match the required constraint %s.',
                    $required['PHP_constraint']['constraint']->asString()
                );
                $hint = $hint ?? 'PHP_constraint';
            }
        }

        if (!empty($required['PHPUnit'])) {
            $phpunitVersion = Version::id();

            $operator = empty($required['PHPUnit']['operator']) ? '>=' : $required['PHPUnit']['operator'];

            if (!\version_compare($phpunitVersion, $required['PHPUnit']['version'], $operator)) {
                $missing[] = \sprintf('PHPUnit %s %s is required.', $operator, $required['PHPUnit']['version']);
                $hint      = $hint ?? 'PHPUnit';
            }
        } elseif (!empty($required['PHPUnit_constraint'])) {
            $phpunitVersion = new \PharIo\Version\Version(self::sanitizeVersionNumber(Version::id()));

            if (!$required['PHPUnit_constraint']['constraint']->complies($phpunitVersion)) {
                $missing[] = \sprintf(
                    'PHPUnit version does not match the required constraint %s.',
                    $required['PHPUnit_constraint']['constraint']->asString()
                );
                $hint = $hint ?? 'PHPUnit_constraint';
            }
        }

        if (!empty($required['OSFAMILY']) && $required['OSFAMILY'] !== (new OperatingSystem)->getFamily()) {
            $missing[] = \sprintf('Operating system %s is required.', $required['OSFAMILY']);
            $hint      = $hint ?? 'OSFAMILY';
        }

        if (!empty($required['OS'])) {
            $requiredOsPattern = \sprintf('/%s/i', \addcslashes($required['OS'], '/'));

            if (!\preg_match($requiredOsPattern, \PHP_OS)) {
                $missing[] = \sprintf('Operating system matching %s is required.', $requiredOsPattern);
                $hint      = $hint ?? 'OS';
            }
        }

        if (!empty($required['functions'])) {
            foreach ($required['functions'] as $function) {
                $pieces = \explode('::', $function);

                if (\count($pieces) === 2 && \method_exists($pieces[0], $pieces[1])) {
                    continue;
                }

                if (\function_exists($function)) {
                    continue;
                }

                $missing[] = \sprintf('Function %s is required.', $function);
                $hint      = $hint ?? 'function_' . $function;
            }
        }

        if (!empty($required['setting'])) {
            foreach ($required['setting'] as $setting => $value) {
                if (\ini_get($setting) != $value) {
                    $missing[] = \sprintf('Setting "%s" must be "%s".', $setting, $value);
                    $hint      = $hint ?? '__SETTING_' . $setting;
                }
            }
        }

        if (!empty($required['extensions'])) {
            foreach ($required['extensions'] as $extension) {
                if (isset($required['extension_versions'][$extension])) {
                    continue;
                }

                if (!\extension_loaded($extension)) {
                    $missing[] = \sprintf('Extension %s is required.', $extension);
                    $hint      = $hint ?? 'extension_' . $extension;
                }
            }
        }

        if (!empty($required['extension_versions'])) {
            foreach ($required['extension_versions'] as $extension => $req) {
                $actualVersion = \phpversion($extension);

                $operator = empty($req['operator']) ? '>=' : $req['operator'];

                if ($actualVersion === false || !\version_compare($actualVersion, $req['version'], $operator)) {
                    $missing[] = \sprintf('Extension %s %s %s is required.', $extension, $operator, $req['version']);
                    $hint      = $hint ?? 'extension_' . $extension;
                }
            }
        }

        if ($hint && isset($required['__OFFSET'])) {
            \array_unshift($missing, '__OFFSET_FILE=' . $required['__OFFSET']['__FILE']);
            \array_unshift($missing, '__OFFSET_LINE=' . ($required['__OFFSET'][$hint] ?? 1));
        }

        return $missing;
    }

    /**
     * Returns the expected exception for a test.
     *
     * @return array|false
     *
     * @deprecated
     * @codeCoverageIgnore
     */
    public static function getExpectedException(string $className, string $methodName)
    {
        try {
            $reflector = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return DocBlock::ofFunction($reflector)
            ->expectedException();
    }

    /**
     * Returns the provided data for a method.
     *
     * @throws Exception
     */
    public static function getProvidedData(string $className, string $methodName): ?array
    {
        try {
            $reflector = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return DocBlock::ofFunction($reflector)
            ->getProvidedData();
    }

    /**
     * @deprecated please use the {@see \PHPUnit\Annotation\Docblock} API instead
     */
    public static function parseTestMethodAnnotations(string $className, ?string $methodName = ''): array
    {
        if (!isset(self::$annotationCache[$className])) {
            try {
                $class = new \ReflectionClass($className);
            } catch (\ReflectionException $e) {
                throw new Exception(
                    $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }

            self::$annotationCache[$className] = DocBlock::ofClass($class)
                ->parseSymbolAnnotations();
        }

        $cacheKey = $className . '::' . $methodName;

        if ($methodName !== null && !isset(self::$annotationCache[$cacheKey])) {
            self::$annotationCache[$cacheKey] = [];

            try {
                $method                           = new \ReflectionMethod($className, $methodName);
                self::$annotationCache[$cacheKey] = DocBlock::ofFunction($method)
                    ->parseSymbolAnnotations();
            } catch (\ReflectionException $e) {
                self::$annotationCache[$cacheKey] = [];
            }
        }

        return [
            'class'  => self::$annotationCache[$className],
            'method' => $methodName !== null ? self::$annotationCache[$cacheKey] : [],
        ];
    }

    public static function getInlineAnnotations(string $className, string $methodName): array
    {
        try {
            $method = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException $e) {
            throw new Exception(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return DocBlock::ofFunction($method)
            ->getInlineAnnotations();
    }

    // @TODO remove
    public static function parseAnnotations(string $docBlock): array
    {
        $annotations = [];
        // Strip away the docblock header and footer to ease parsing of one line annotations
        $docBlock = (string) \substr($docBlock, 3, -2);

        if (\preg_match_all('/@(?P<name>[A-Za-z_-]+)(?:[ \t]+(?P<value>[^\r\n]*?))?[ \t]*\r?$/m', $docBlock, $matches)) {
            $numMatches = \count($matches[0]);

            for ($i = 0; $i < $numMatches; ++$i) {
                $annotations[$matches['name'][$i]][] = (string) $matches['value'][$i];
            }
        }

        return $annotations;
    }

    public static function getBackupSettings(string $className, string $methodName): array
    {
        return [
            'backupGlobals' => self::getBooleanAnnotationSetting(
                $className,
                $methodName,
                'backupGlobals'
            ),
            'backupStaticAttributes' => self::getBooleanAnnotationSetting(
                $className,
                $methodName,
                'backupStaticAttributes'
            ),
        ];
    }

    public static function getDependencies(string $className, string $methodName): array
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        $dependencies = $annotations['class']['depends'] ?? [];

        if (isset($annotations['method']['depends'])) {
            $dependencies = \array_merge(
                $dependencies,
                $annotations['method']['depends']
            );
        }

        return \array_unique($dependencies);
    }

    public static function getGroups(string $className, ?string $methodName = ''): array
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        $groups = [];

        if (isset($annotations['method']['author'])) {
            $groups = $annotations['method']['author'];
        } elseif (isset($annotations['class']['author'])) {
            $groups = $annotations['class']['author'];
        }

        if (isset($annotations['class']['group'])) {
            $groups = \array_merge($groups, $annotations['class']['group']);
        }

        if (isset($annotations['method']['group'])) {
            $groups = \array_merge($groups, $annotations['method']['group']);
        }

        if (isset($annotations['class']['ticket'])) {
            $groups = \array_merge($groups, $annotations['class']['ticket']);
        }

        if (isset($annotations['method']['ticket'])) {
            $groups = \array_merge($groups, $annotations['method']['ticket']);
        }

        foreach (['method', 'class'] as $element) {
            foreach (['small', 'medium', 'large'] as $size) {
                if (isset($annotations[$element][$size])) {
                    $groups[] = $size;

                    break 2;
                }
            }
        }

        return \array_unique($groups);
    }

    public static function getSize(string $className, ?string $methodName): int
    {
        $groups = \array_flip(self::getGroups($className, $methodName));

        if (isset($groups['large'])) {
            return self::LARGE;
        }

        if (isset($groups['medium'])) {
            return self::MEDIUM;
        }

        if (isset($groups['small'])) {
            return self::SMALL;
        }

        return self::UNKNOWN;
    }

    public static function getProcessIsolationSettings(string $className, string $methodName): bool
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        return isset($annotations['class']['runTestsInSeparateProcesses']) || isset($annotations['method']['runInSeparateProcess']);
    }

    public static function getClassProcessIsolationSettings(string $className, string $methodName): bool
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        return isset($annotations['class']['runClassInSeparateProcess']);
    }

    public static function getPreserveGlobalStateSettings(string $className, string $methodName): ?bool
    {
        return self::getBooleanAnnotationSetting(
            $className,
            $methodName,
            'preserveGlobalState'
        );
    }

    public static function getHookMethods(string $className): array
    {
        if (!\class_exists($className, false)) {
            return self::emptyHookMethodsArray();
        }

        if (!isset(self::$hookMethods[$className])) {
            self::$hookMethods[$className] = self::emptyHookMethodsArray();

            try {
                foreach ((new \ReflectionClass($className))->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() === Assert::class) {
                        continue;
                    }

                    if ($method->getDeclaringClass()->getName() === TestCase::class) {
                        continue;
                    }

                    $methodComment = $method->getDocComment();

                    if ($methodComment) {
                        if ($method->isStatic()) {
                            if (\strpos($methodComment, '@beforeClass') !== false) {
                                \array_unshift(
                                    self::$hookMethods[$className]['beforeClass'],
                                    $method->getName()
                                );
                            }

                            if (\strpos($methodComment, '@afterClass') !== false) {
                                self::$hookMethods[$className]['afterClass'][] = $method->getName();
                            }
                        }

                        if (\preg_match('/@before\b/', $methodComment) > 0) {
                            \array_unshift(
                                self::$hookMethods[$className]['before'],
                                $method->getName()
                            );
                        }

                        if (\preg_match('/@after\b/', $methodComment) > 0) {
                            self::$hookMethods[$className]['after'][] = $method->getName();
                        }
                    }
                }
            } catch (\ReflectionException $e) {
            }
        }

        return self::$hookMethods[$className];
    }

    public static function isTestMethod(\ReflectionMethod $method): bool
    {
        if (\strpos($method->getName(), 'test') === 0) {
            return true;
        }

        $annotations = self::parseAnnotations((string) $method->getDocComment());

        return isset($annotations['test']);
    }

    /**
     * @throws CodeCoverageException
     */
    private static function getLinesToBeCoveredOrUsed(string $className, string $methodName, string $mode): array
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        $classShortcut = null;

        if (!empty($annotations['class'][$mode . 'DefaultClass'])) {
            if (\count($annotations['class'][$mode . 'DefaultClass']) > 1) {
                throw new CodeCoverageException(
                    \sprintf(
                        'More than one @%sClass annotation in class or interface "%s".',
                        $mode,
                        $className
                    )
                );
            }

            $classShortcut = $annotations['class'][$mode . 'DefaultClass'][0];
        }

        $list = $annotations['class'][$mode] ?? [];

        if (isset($annotations['method'][$mode])) {
            $list = \array_merge($list, $annotations['method'][$mode]);
        }

        $codeList = [];

        foreach (\array_unique($list) as $element) {
            if ($classShortcut && \strncmp($element, '::', 2) === 0) {
                $element = $classShortcut . $element;
            }

            $element = \preg_replace('/[\s()]+$/', '', $element);
            $element = \explode(' ', $element);
            $element = $element[0];

            if ($mode === 'covers' && \interface_exists($element)) {
                throw new InvalidCoversTargetException(
                    \sprintf(
                        'Trying to @cover interface "%s".',
                        $element
                    )
                );
            }

            $codeList = \array_merge(
                $codeList,
                self::resolveElementToReflectionObjects($element)
            );
        }

        return self::resolveReflectionObjectsToLines($codeList);
    }

    /**
     * Parse annotation content to use constant/class constant values
     *
     * Constants are specified using a starting '@'. For example: @ClassName::CONST_NAME
     *
     * If the constant is not found the string is used as is to ensure maximum BC.
     */
    private static function parseAnnotationContent(string $message): string
    {
        if (\defined($message) && (\strpos($message, '::') !== false && \substr_count($message, '::') + 1 === 2)) {
            $message = \constant($message);
        }

        return $message;
    }

    private static function cleanUpMultiLineAnnotation(string $docComment): string
    {
        //removing initial '   * ' for docComment
        $docComment = \str_replace("\r\n", "\n", $docComment);
        $docComment = \preg_replace('/' . '\n' . '\s*' . '\*' . '\s?' . '/', "\n", $docComment);
        $docComment = (string) \substr($docComment, 0, -1);

        return \rtrim($docComment, "\n");
    }

    private static function emptyHookMethodsArray(): array
    {
        return [
            'beforeClass' => ['setUpBeforeClass'],
            'before'      => ['setUp'],
            'after'       => ['tearDown'],
            'afterClass'  => ['tearDownAfterClass'],
        ];
    }

    private static function getBooleanAnnotationSetting(string $className, ?string $methodName, string $settingName): ?bool
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        if (isset($annotations['method'][$settingName])) {
            if ($annotations['method'][$settingName][0] === 'enabled') {
                return true;
            }

            if ($annotations['method'][$settingName][0] === 'disabled') {
                return false;
            }
        }

        if (isset($annotations['class'][$settingName])) {
            if ($annotations['class'][$settingName][0] === 'enabled') {
                return true;
            }

            if ($annotations['class'][$settingName][0] === 'disabled') {
                return false;
            }
        }

        return null;
    }

    /**
     * @throws InvalidCoversTargetException
     */
    private static function resolveElementToReflectionObjects(string $element): array
    {
        $codeToCoverList = [];

        if (\function_exists($element) && \strpos($element, '\\') !== false) {
            try {
                $codeToCoverList[] = new \ReflectionFunction($element);
            } catch (\ReflectionException $e) {
                throw new Exception(
                    $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        } elseif (\strpos($element, '::') !== false) {
            [$className, $methodName] = \explode('::', $element);

            if (isset($methodName[0]) && $methodName[0] === '<') {
                $classes = [$className];

                foreach ($classes as $className) {
                    if (!\class_exists($className) &&
                        !\interface_exists($className) &&
                        !\trait_exists($className)) {
                        throw new InvalidCoversTargetException(
                            \sprintf(
                                'Trying to @cover or @use not existing class or ' .
                                'interface "%s".',
                                $className
                            )
                        );
                    }

                    try {
                        $methods = (new \ReflectionClass($className))->getMethods();
                    } catch (\ReflectionException $e) {
                        throw new Exception(
                            $e->getMessage(),
                            (int) $e->getCode(),
                            $e
                        );
                    }

                    $inverse    = isset($methodName[1]) && $methodName[1] === '!';
                    $visibility = 'isPublic';

                    if (\strpos($methodName, 'protected')) {
                        $visibility = 'isProtected';
                    } elseif (\strpos($methodName, 'private')) {
                        $visibility = 'isPrivate';
                    }

                    foreach ($methods as $method) {
                        if ($inverse && !$method->$visibility()) {
                            $codeToCoverList[] = $method;
                        } elseif (!$inverse && $method->$visibility()) {
                            $codeToCoverList[] = $method;
                        }
                    }
                }
            } else {
                $classes = [$className];

                foreach ($classes as $className) {
                    if ($className === '' && \function_exists($methodName)) {
                        try {
                            $codeToCoverList[] = new \ReflectionFunction(
                                $methodName
                            );
                        } catch (\ReflectionException $e) {
                            throw new Exception(
                                $e->getMessage(),
                                (int) $e->getCode(),
                                $e
                            );
                        }
                    } else {
                        if (!((\class_exists($className) || \interface_exists($className) || \trait_exists($className)) &&
                            \method_exists($className, $methodName))) {
                            throw new InvalidCoversTargetException(
                                \sprintf(
                                    'Trying to @cover or @use not existing method "%s::%s".',
                                    $className,
                                    $methodName
                                )
                            );
                        }

                        try {
                            $codeToCoverList[] = new \ReflectionMethod(
                                $className,
                                $methodName
                            );
                        } catch (\ReflectionException $e) {
                            throw new Exception(
                                $e->getMessage(),
                                (int) $e->getCode(),
                                $e
                            );
                        }
                    }
                }
            }
        } else {
            $extended = false;

            if (\strpos($element, '<extended>') !== false) {
                $element  = \str_replace('<extended>', '', $element);
                $extended = true;
            }

            $classes = [$element];

            if ($extended) {
                $classes = \array_merge(
                    $classes,
                    \class_implements($element),
                    \class_parents($element)
                );
            }

            foreach ($classes as $className) {
                if (!\class_exists($className) &&
                    !\interface_exists($className) &&
                    !\trait_exists($className)) {
                    throw new InvalidCoversTargetException(
                        \sprintf(
                            'Trying to @cover or @use not existing class or ' .
                            'interface "%s".',
                            $className
                        )
                    );
                }

                try {
                    $codeToCoverList[] = new \ReflectionClass($className);
                } catch (\ReflectionException $e) {
                    throw new Exception(
                        $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            }
        }

        return $codeToCoverList;
    }

    private static function resolveReflectionObjectsToLines(array $reflectors): array
    {
        $result = [];

        foreach ($reflectors as $reflector) {
            if ($reflector instanceof \ReflectionClass) {
                foreach ($reflector->getTraits() as $trait) {
                    $reflectors[] = $trait;
                }
            }
        }

        foreach ($reflectors as $reflector) {
            $filename = $reflector->getFileName();

            if (!isset($result[$filename])) {
                $result[$filename] = [];
            }

            $result[$filename] = \array_merge(
                $result[$filename],
                \range($reflector->getStartLine(), $reflector->getEndLine())
            );
        }

        foreach ($result as $filename => $lineNumbers) {
            $result[$filename] = \array_keys(\array_flip($lineNumbers));
        }

        return $result;
    }

    /**
     * Trims any extensions from version string that follows after
     * the <major>.<minor>[.<patch>] format
     */
    private static function sanitizeVersionNumber(string $version)
    {
        return \preg_replace(
            '/^(\d+\.\d+(?:.\d+)?).*$/',
            '$1',
            $version
        );
    }

    private static function shouldCoversAnnotationBeUsed(array $annotations): bool
    {
        if (isset($annotations['method']['coversNothing'])) {
            return false;
        }

        if (isset($annotations['method']['covers'])) {
            return true;
        }

        if (isset($annotations['class']['coversNothing'])) {
            return false;
        }

        return true;
    }

    /**
     * @TODO isolate to own file if used more than once
     * @TODO add ZendFramework license note
     * @TODO this is copied from https://github.com/zendframework/zend-stdlib/blob/master/src/ArrayUtils.php
     */
    private static function mergeThingsRecursivelyBecausePHPsArrayMergeRecursiveIsUselessBurningGarbage(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (\array_key_exists($key, $a)) {
                if (\is_int($key)) {
                    $a[] = $value;
                } elseif (\is_array($value) && \is_array($a[$key])) {
                    $a[$key] = self::mergeThingsRecursivelyBecausePHPsArrayMergeRecursiveIsUselessBurningGarbage($a[$key], $value);
                } else {
                    $a[$key] = $value;
                }
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }
}
