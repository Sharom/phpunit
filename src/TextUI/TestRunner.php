<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\TextUI;

use const PHP_SAPI;
use const PHP_VERSION;
use function array_map;
use function file_put_contents;
use function htmlspecialchars;
use function is_file;
use function mt_srand;
use function range;
use function sprintf;
use function unlink;
use PHPUnit\Event;
use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Logging\EventLogger;
use PHPUnit\Logging\JUnit\JunitXmlLogger;
use PHPUnit\Logging\TeamCity\TeamCityLogger;
use PHPUnit\Logging\TestDox\HtmlRenderer as TestDoxHtmlRenderer;
use PHPUnit\Logging\TestDox\PlainTextRenderer as TestDoxTextRenderer;
use PHPUnit\Logging\TestDox\TestResultCollector;
use PHPUnit\Logging\TestDox\XmlRenderer as TestDoxXmlRenderer;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\Extension\PharLoader;
use PHPUnit\Runner\Filter\Factory;
use PHPUnit\Runner\ResultCache\DefaultResultCache;
use PHPUnit\Runner\ResultCache\NullResultCache;
use PHPUnit\Runner\ResultCache\ResultCacheHandler;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\Runner\Version;
use PHPUnit\TestRunner\TestResult\Facade;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\CodeCoverageReportNotConfiguredException;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\FilterNotConfiguredException;
use PHPUnit\TextUI\Configuration\LoggingNotConfiguredException;
use PHPUnit\TextUI\Configuration\NoBootstrapException;
use PHPUnit\TextUI\Configuration\NoConfigurationFileException;
use PHPUnit\TextUI\Configuration\NoCoverageCacheDirectoryException;
use PHPUnit\TextUI\Configuration\NoCustomCssFileException;
use PHPUnit\TextUI\Configuration\NoPharExtensionDirectoryException;
use PHPUnit\TextUI\Configuration\NoValidationErrorsException;
use PHPUnit\TextUI\Output\Default\ProgressPrinter\ProgressPrinter as DefaultProgressPrinter;
use PHPUnit\TextUI\Output\Default\ResultPrinter as DefaultResultPrinter;
use PHPUnit\TextUI\Output\SummaryPrinter;
use PHPUnit\TextUI\Output\TestDox\ResultPrinter as TestDoxResultPrinter;
use PHPUnit\Util\DefaultPrinter;
use PHPUnit\Util\DirectoryDoesNotExistException;
use PHPUnit\Util\InvalidSocketException;
use PHPUnit\Util\NullPrinter;
use PHPUnit\Util\Printer;
use PHPUnit\Util\Xml\SchemaDetector;
use SebastianBergmann\CodeCoverage\Driver\PcovNotAvailableException;
use SebastianBergmann\CodeCoverage\Driver\XdebugNotAvailableException;
use SebastianBergmann\CodeCoverage\Driver\XdebugNotEnabledException;
use SebastianBergmann\CodeCoverage\Exception as CodeCoverageException;
use SebastianBergmann\CodeCoverage\InvalidArgumentException;
use SebastianBergmann\CodeCoverage\NoCodeCoverageDriverAvailableException;
use SebastianBergmann\CodeCoverage\NoCodeCoverageDriverWithPathCoverageSupportAvailableException;
use SebastianBergmann\CodeCoverage\Report\Clover as CloverReport;
use SebastianBergmann\CodeCoverage\Report\Cobertura as CoberturaReport;
use SebastianBergmann\CodeCoverage\Report\Crap4j as Crap4jReport;
use SebastianBergmann\CodeCoverage\Report\Html\Colors;
use SebastianBergmann\CodeCoverage\Report\Html\CustomCssFile;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;
use SebastianBergmann\CodeCoverage\Report\Text as TextReport;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Report\Xml\Facade as XmlReport;
use SebastianBergmann\CodeCoverage\UnintentionallyCoveredCodeException;
use SebastianBergmann\Comparator\Comparator;
use SebastianBergmann\Invoker\Invoker;
use SebastianBergmann\Timer\NoActiveTimerException;
use SebastianBergmann\Timer\ResourceUsageFormatter;
use SebastianBergmann\Timer\Timer;
use SebastianBergmann\Timer\TimeSinceStartOfRequestNotAvailableException;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class TestRunner
{
    private Printer $printer;
    private bool $messagePrinted = false;
    private ?Timer $timer        = null;

    /**
     * @throws \PHPUnit\Runner\Exception
     * @throws \PHPUnit\Util\Exception
     * @throws CodeCoverageReportNotConfiguredException
     * @throws Event\EventFacadeIsSealedException
     * @throws Event\RuntimeException
     * @throws Event\UnknownSubscriberTypeException
     * @throws Exception
     * @throws FilterNotConfiguredException
     * @throws InvalidArgumentException
     * @throws LoggingNotConfiguredException
     * @throws NoActiveTimerException
     * @throws NoBootstrapException
     * @throws NoCodeCoverageDriverAvailableException
     * @throws NoCodeCoverageDriverWithPathCoverageSupportAvailableException
     * @throws NoConfigurationFileException
     * @throws NoCoverageCacheDirectoryException
     * @throws NoCustomCssFileException
     * @throws NoPharExtensionDirectoryException
     * @throws NoPreviousThrowableException
     * @throws NoValidationErrorsException
     * @throws PcovNotAvailableException
     * @throws TimeSinceStartOfRequestNotAvailableException
     * @throws UnintentionallyCoveredCodeException
     * @throws XdebugNotAvailableException
     * @throws XdebugNotEnabledException
     * @throws XmlConfiguration\Exception
     */
    public function run(Configuration $configuration, TestSuite $suite): TestResult
    {
        Event\Facade::emitter()->testRunnerStarted();

        if ($configuration->hasCoverageReport()) {
            CodeCoverageFilterRegistry::init($configuration);
        }

        if ($configuration->hasConfigurationFile()) {
            $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration->configurationFile();
        }

        if ($configuration->loadPharExtensions() &&
            $configuration->hasPharExtensionDirectory()) {
            $pharExtensions = (new PharLoader)->loadPharExtensionsInDirectory(
                $configuration->pharExtensionDirectory()
            );
        }

        if ($configuration->hasBootstrap()) {
            $GLOBALS['__PHPUNIT_BOOTSTRAP'] = $configuration->bootstrap();
        }

        if ($configuration->executionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            mt_srand($configuration->randomOrderSeed());
        }

        if ($configuration->cacheResult()) {
            $cache = new DefaultResultCache($configuration->testResultCacheFile());

            new ResultCacheHandler($cache);
        }

        if ($configuration->executionOrder() !== TestSuiteSorter::ORDER_DEFAULT ||
            $configuration->executionOrderDefects() !== TestSuiteSorter::ORDER_DEFAULT ||
            $configuration->resolveDependencies()) {
            $cache = $cache ?? new NullResultCache;

            $cache->load();

            $sorter = new TestSuiteSorter($cache);

            $sorter->reorderTestsInSuite(
                $suite,
                $configuration->executionOrder(),
                $configuration->resolveDependencies(),
                $configuration->executionOrderDefects()
            );

            Event\Facade::emitter()->testSuiteSorted(
                $configuration->executionOrder(),
                $configuration->executionOrderDefects(),
                $configuration->resolveDependencies()
            );

            unset($sorter);
        }

        if ($configuration->hasRepeat()) {
            $_suite = TestSuite::empty();

            /* @noinspection PhpUnusedLocalVariableInspection */
            foreach (range(1, $configuration->repeat()) as $step) {
                $_suite->addTest($suite);
            }

            $suite = $_suite;

            unset($_suite);
        }

        $this->printer = new NullPrinter;

        if ($this->useDefaultProgressPrinter($configuration) ||
            $this->useDefaultResultPrinter($configuration) ||
            $configuration->outputIsTestDox()) {
            if ($configuration->outputToStandardErrorStream()) {
                $this->printer = DefaultPrinter::standardError();
            } else {
                $this->printer = DefaultPrinter::standardOutput();
            }

            if ($this->useDefaultProgressPrinter($configuration)) {
                $progressPrinter = new DefaultProgressPrinter(
                    $this->printer,
                    $configuration->colors(),
                    $configuration->columns()
                );
            }
        }

        if ($this->useDefaultResultPrinter($configuration)) {
            $resultPrinter = new DefaultResultPrinter(
                $this->printer,
                $configuration->displayDetailsOnIncompleteTests(),
                $configuration->displayDetailsOnSkippedTests(),
                $configuration->displayDetailsOnTestsThatTriggerDeprecations(),
                $configuration->displayDetailsOnTestsThatTriggerErrors(),
                $configuration->displayDetailsOnTestsThatTriggerNotices(),
                $configuration->displayDetailsOnTestsThatTriggerWarnings(),
                $configuration->reverseDefectList()
            );

            $summaryPrinter = new SummaryPrinter(
                $this->printer,
                $configuration->colors(),
            );
        }

        Facade::init();

        if ($configuration->hasLogEventsText()) {
            if (is_file($configuration->logEventsText())) {
                unlink($configuration->logEventsText());
            }

            Event\Facade::registerTracer(
                new EventLogger(
                    $configuration->logEventsText(),
                    false
                )
            );
        }

        if ($configuration->hasLogEventsVerboseText()) {
            if (is_file($configuration->logEventsVerboseText())) {
                unlink($configuration->logEventsVerboseText());
            }

            Event\Facade::registerTracer(
                new EventLogger(
                    $configuration->logEventsVerboseText(),
                    true
                )
            );
        }

        if ($configuration->hasLogfileJunit()) {
            $junitXmlLogger = new JunitXmlLogger(
                $configuration->reportUselessTests()
            );
        }

        if ($configuration->hasLogfileTeamcity()) {
            $teamCityLogger = new TeamCityLogger(
                DefaultPrinter::from(
                    $configuration->logfileTeamcity()
                )
            );
        }

        if ($configuration->outputIsTeamCity()) {
            $teamCityOutput = new TeamCityLogger(
                DefaultPrinter::standardOutput()
            );
        }

        if ($configuration->hasLogfileTestdoxHtml() ||
            $configuration->hasLogfileTestdoxText() ||
            $configuration->hasLogfileTestdoxXml() ||
            $configuration->outputIsTestDox()) {
            $testDoxCollector = new TestResultCollector;

            if ($configuration->outputIsTestDox()) {
                $summaryPrinter = new SummaryPrinter(
                    $this->printer,
                    $configuration->colors(),
                );
            }
        }

        Event\Facade::seal();

        $this->write(Version::getVersionString() . "\n");

        if ($configuration->hasLogfileText()) {
            $textLogger = new DefaultResultPrinter(
                DefaultPrinter::from($configuration->logfileText()),
                true,
                true,
                true,
                true,
                true,
                true,
                false,
            );
        }

        if ($configuration->hasCoverageReport()) {
            if ($configuration->pathCoverage()) {
                CodeCoverage::activate(CodeCoverageFilterRegistry::get(), true);
            } else {
                CodeCoverage::activate(CodeCoverageFilterRegistry::get(), false);
            }

            if (CodeCoverage::isActive()) {
                if ($configuration->hasCoverageCacheDirectory()) {
                    CodeCoverage::instance()->cacheStaticAnalysis($configuration->coverageCacheDirectory());
                }

                CodeCoverage::instance()->excludeSubclassesOfThisClassFromUnintentionallyCoveredCodeCheck(Comparator::class);

                if ($configuration->strictCoverage()) {
                    CodeCoverage::instance()->enableCheckForUnintentionallyCoveredCode();
                }

                if ($configuration->ignoreDeprecatedCodeUnitsFromCodeCoverage()) {
                    CodeCoverage::instance()->ignoreDeprecatedCode();
                } else {
                    CodeCoverage::instance()->doNotIgnoreDeprecatedCode();
                }

                if ($configuration->disableCodeCoverageIgnore()) {
                    CodeCoverage::instance()->disableAnnotationsForIgnoringCode();
                } else {
                    CodeCoverage::instance()->enableAnnotationsForIgnoringCode();
                }

                if ($configuration->includeUncoveredFiles()) {
                    CodeCoverage::instance()->includeUncoveredFiles();
                } else {
                    CodeCoverage::instance()->excludeUncoveredFiles();
                }

                if (CodeCoverageFilterRegistry::get()->isEmpty()) {
                    if (!CodeCoverageFilterRegistry::configured()) {
                        Event\Facade::emitter()->testRunnerTriggeredWarning(
                            'No filter is configured, code coverage will not be processed'
                        );
                    } else {
                        Event\Facade::emitter()->testRunnerTriggeredWarning(
                            'Incorrect filter configuration, code coverage will not be processed'
                        );
                    }

                    CodeCoverage::deactivate();
                }
            }
        }

        if (PHP_SAPI === 'phpdbg') {
            $this->writeMessage('Runtime', 'PHPDBG ' . PHP_VERSION);
        } else {
            $runtime = 'PHP ' . PHP_VERSION;

            if (CodeCoverage::isActive()) {
                $runtime .= ' with ' . CodeCoverage::driver()->nameAndVersion();
            }

            $this->writeMessage('Runtime', $runtime);
        }

        if ($configuration->hasConfigurationFile()) {
            $this->writeMessage(
                'Configuration',
                $configuration->configurationFile()
            );
        }

        if (isset($pharExtensions)) {
            foreach ($pharExtensions['loadedExtensions'] as $extension) {
                $this->writeMessage(
                    'Extension',
                    $extension
                );
            }

            foreach ($pharExtensions['notLoadedExtensions'] as $extension) {
                $this->writeMessage(
                    'Extension',
                    $extension
                );
            }
        }

        if ($configuration->executionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            $this->writeMessage(
                'Random Seed',
                (string) $configuration->randomOrderSeed()
            );
        }

        if ($configuration->tooFewColumnsRequested()) {
            Event\Facade::emitter()->testRunnerTriggeredWarning(
                'Less than 16 columns requested, number of columns set to 16'
            );
        }

        if ($configuration->hasXmlValidationErrors()) {
            if ((new SchemaDetector)->detect($configuration->configurationFile())->detected()) {
                Event\Facade::emitter()->testRunnerTriggeredWarning(
                    'Your XML configuration validates against a deprecated schema. Migrate your XML configuration using "--migrate-configuration"!'
                );
            } else {
                Event\Facade::emitter()->testRunnerTriggeredWarning(
                    "Test results may not be as expected because the XML configuration file did not pass validation:\n" .
                    $configuration->xmlValidationErrors()
                );
            }
        }

        $this->write("\n");

        if ($configuration->enforceTimeLimit() && !(new Invoker)->canInvokeWithTimeout()) {
            Event\Facade::emitter()->testRunnerTriggeredWarning(
                'The pcntl extension is required for enforcing time limits'
            );
        }

        $this->processSuiteFilters($configuration, $suite);

        Event\Facade::emitter()->testRunnerExecutionStarted(
            Event\TestSuite\TestSuite::fromTestSuite($suite)
        );

        $suite->run();

        Event\Facade::emitter()->testRunnerExecutionFinished();

        $result = Facade::result();

        if ($result->numberOfTestsRun() > 0) {
            if (isset($progressPrinter)) {
                $this->printer->print(PHP_EOL . PHP_EOL);
            }

            $this->printer->print((new ResourceUsageFormatter)->resourceUsageSinceStartOfRequest() . PHP_EOL . PHP_EOL);
        }

        if (isset($resultPrinter, $summaryPrinter)) {
            $resultPrinter->print($result);
            $summaryPrinter->print($result);
        }

        if (isset($junitXmlLogger)) {
            file_put_contents(
                $configuration->logfileJunit(),
                $junitXmlLogger->flush()
            );
        }

        if (isset($teamCityLogger)) {
            $teamCityLogger->flush();
        }

        if (isset($teamCityOutput)) {
            $teamCityOutput->flush();
        }

        if (isset($textLogger)) {
            $textLogger->flush();
        }

        if (isset($testDoxCollector, $summaryPrinter) &&
             $configuration->outputIsTestDox()) {
            (new TestDoxResultPrinter($this->printer, $configuration->colors()))->print(
                $testDoxCollector->testMethodsGroupedByClass()
            );

            $summaryPrinter->print($result);
        }

        if (isset($testDoxCollector) &&
            $configuration->hasLogfileTestdoxHtml()) {
            $this->printerFor($configuration->logfileTestdoxHtml())->print(
                (new TestDoxHtmlRenderer)->render(
                    $testDoxCollector->testMethodsGroupedByClass()
                )
            );
        }

        if (isset($testDoxCollector) &&
            $configuration->hasLogfileTestdoxText()) {
            $this->printerFor($configuration->logfileTestdoxText())->print(
                (new TestDoxTextRenderer)->render(
                    $testDoxCollector->testMethodsGroupedByClass()
                )
            );
        }

        if (isset($testDoxCollector) &&
            $configuration->hasLogfileTestdoxXml()) {
            $this->printerFor($configuration->logfileTestdoxXml())->print(
                (new TestDoxXmlRenderer)->render(
                    $testDoxCollector->testMethodsGroupedByClass()
                )
            );
        }

        if (CodeCoverage::isActive()) {
            if ($configuration->hasCoverageClover()) {
                $this->codeCoverageGenerationStart('Clover XML');

                try {
                    $writer = new CloverReport;
                    $writer->process(CodeCoverage::instance(), $configuration->coverageClover());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }

            if ($configuration->hasCoverageCobertura()) {
                $this->codeCoverageGenerationStart('Cobertura XML');

                try {
                    $writer = new CoberturaReport;
                    $writer->process(CodeCoverage::instance(), $configuration->coverageCobertura());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }

            if ($configuration->hasCoverageCrap4j()) {
                $this->codeCoverageGenerationStart('Crap4J XML');

                try {
                    $writer = new Crap4jReport($configuration->coverageCrap4jThreshold());
                    $writer->process(CodeCoverage::instance(), $configuration->coverageCrap4j());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }

            if ($configuration->hasCoverageHtml()) {
                $this->codeCoverageGenerationStart('HTML');

                try {
                    $customCssFile = CustomCssFile::default();

                    if ($configuration->hasCoverageHtmlCustomCssFile()) {
                        $customCssFile = CustomCssFile::from($configuration->coverageHtmlCustomCssFile());
                    }

                    $writer = new HtmlReport(
                        sprintf(
                            ' and <a href="https://phpunit.de/">PHPUnit %s</a>',
                            Version::id()
                        ),
                        Colors::from(
                            $configuration->coverageHtmlColorSuccessLow(),
                            $configuration->coverageHtmlColorSuccessMedium(),
                            $configuration->coverageHtmlColorSuccessHigh(),
                            $configuration->coverageHtmlColorWarning(),
                            $configuration->coverageHtmlColorDanger(),
                        ),
                        Thresholds::from(
                            $configuration->coverageHtmlLowUpperBound(),
                            $configuration->coverageHtmlHighLowerBound()
                        ),
                        $customCssFile
                    );

                    $writer->process(CodeCoverage::instance(), $configuration->coverageHtml());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }

            if ($configuration->hasCoveragePhp()) {
                $this->codeCoverageGenerationStart('PHP');

                try {
                    $writer = new PhpReport;
                    $writer->process(CodeCoverage::instance(), $configuration->coveragePhp());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }

            if ($configuration->hasCoverageText()) {
                $processor = new TextReport(
                    Thresholds::default(),
                    $configuration->coverageTextShowUncoveredFiles(),
                    $configuration->coverageTextShowOnlySummary()
                );

                $this->printerFor($configuration->coverageText())->print(
                    $processor->process(CodeCoverage::instance(), $configuration->colors())
                );
            }

            if ($configuration->hasCoverageXml()) {
                $this->codeCoverageGenerationStart('PHPUnit XML');

                try {
                    $writer = new XmlReport(Version::id());
                    $writer->process(CodeCoverage::instance(), $configuration->coverageXml());

                    $this->codeCoverageGenerationSucceeded();

                    unset($writer);
                } catch (CodeCoverageException $e) {
                    $this->codeCoverageGenerationFailed($e);
                }
            }
        }

        Event\Facade::emitter()->testRunnerFinished();

        return $result;
    }

    private function write(string $buffer): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            $buffer = htmlspecialchars($buffer);
        }

        $this->printer->print($buffer);
    }

    /**
     * @throws Event\RuntimeException
     * @throws FilterNotConfiguredException
     */
    private function processSuiteFilters(Configuration $configuration, TestSuite $suite): void
    {
        if (!$configuration->hasFilter() &&
            !$configuration->hasGroups() &&
            !$configuration->hasExcludeGroups() &&
            !$configuration->hasTestsCovering() &&
            !$configuration->hasTestsUsing()) {
            return;
        }

        $filterFactory = new Factory;

        if ($configuration->hasExcludeGroups()) {
            $filterFactory->addExcludeGroupFilter(
                $configuration->excludeGroups()
            );
        }

        if ($configuration->hasGroups()) {
            $filterFactory->addIncludeGroupFilter(
                $configuration->groups()
            );
        }

        if ($configuration->hasTestsCovering()) {
            $filterFactory->addIncludeGroupFilter(
                array_map(
                    static fn (string $name): string => '__phpunit_covers_' . $name,
                    $configuration->testsCovering()
                )
            );
        }

        if ($configuration->hasTestsUsing()) {
            $filterFactory->addIncludeGroupFilter(
                array_map(
                    static fn (string $name): string => '__phpunit_uses_' . $name,
                    $configuration->testsUsing()
                )
            );
        }

        if ($configuration->hasFilter()) {
            $filterFactory->addNameFilter(
                $configuration->filter()
            );
        }

        $suite->injectFilter($filterFactory);

        Event\Facade::emitter()->testSuiteFiltered(
            Event\TestSuite\TestSuite::fromTestSuite($suite)
        );
    }

    private function writeMessage(string $type, string $message): void
    {
        if (!$this->messagePrinted) {
            $this->write("\n");
        }

        $this->write(
            sprintf(
                "%-15s%s\n",
                $type . ':',
                $message
            )
        );

        $this->messagePrinted = true;
    }

    private function codeCoverageGenerationStart(string $format): void
    {
        $this->write(
            sprintf(
                "\nGenerating code coverage report in %s format ... ",
                $format
            )
        );

        $this->timer()->start();
    }

    /**
     * @throws NoActiveTimerException
     */
    private function codeCoverageGenerationSucceeded(): void
    {
        $this->write(
            sprintf(
                "done [%s]\n",
                $this->timer()->stop()->asString()
            )
        );
    }

    /**
     * @throws NoActiveTimerException
     */
    private function codeCoverageGenerationFailed(CodeCoverageException $e): void
    {
        $this->write(
            sprintf(
                "failed [%s]\n%s\n",
                $this->timer()->stop()->asString(),
                $e->getMessage()
            )
        );
    }

    private function timer(): Timer
    {
        if ($this->timer === null) {
            $this->timer = new Timer;
        }

        return $this->timer;
    }

    /**
     * @throws DirectoryDoesNotExistException
     * @throws InvalidSocketException
     */
    private function printerFor(string $target): Printer
    {
        if ($target === 'php://stdout') {
            return $this->printer;
        }

        return DefaultPrinter::from($target);
    }

    private function useDefaultProgressPrinter(Configuration $configuration): bool
    {
        if ($configuration->noOutput()) {
            return false;
        }

        if ($configuration->noProgress()) {
            return false;
        }

        if ($configuration->outputIsTeamCity()) {
            return false;
        }

        return true;
    }

    private function useDefaultResultPrinter(Configuration $configuration): bool
    {
        if ($configuration->noOutput()) {
            return false;
        }

        if ($configuration->noResults()) {
            return false;
        }

        if ($configuration->outputIsTeamCity()) {
            return false;
        }

        if ($configuration->outputIsTestDox()) {
            return false;
        }

        return true;
    }
}
