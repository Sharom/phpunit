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

use const PHP_EOL;
use function is_readable;
use function printf;
use function realpath;
use function sprintf;
use function trim;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\Extension\ExtensionBootstrapper;
use PHPUnit\Runner\Extension\Facade as ExtensionFacade;
use PHPUnit\Runner\Version;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\CliArguments\Configuration as CliConfiguration;
use PHPUnit\TextUI\CliArguments\Exception as ArgumentsException;
use PHPUnit\TextUI\Command\AtLeastVersionCommand;
use PHPUnit\TextUI\Command\GenerateConfigurationCommand;
use PHPUnit\TextUI\Command\ListGroupsCommand;
use PHPUnit\TextUI\Command\ListTestsAsTextCommand;
use PHPUnit\TextUI\Command\ListTestsAsXmlCommand;
use PHPUnit\TextUI\Command\ListTestSuitesCommand;
use PHPUnit\TextUI\Command\MigrateConfigurationCommand;
use PHPUnit\TextUI\Command\Result;
use PHPUnit\TextUI\Command\ShowHelpCommand;
use PHPUnit\TextUI\Command\ShowVersionCommand;
use PHPUnit\TextUI\Command\VersionCheckCommand;
use PHPUnit\TextUI\Command\WarmCodeCoverageCacheCommand;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\XmlConfiguration\Configuration as XmlConfiguration;
use PHPUnit\TextUI\XmlConfiguration\ConfigurationFileFinder;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use Throwable;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Application
{
    private bool $versionStringPrinted = false;

    public function run(array $argv): int
    {
        try {
            Facade::emitter()->applicationStarted();

            $cliConfiguration           = $this->buildCliConfiguration($argv);
            $pathToXmlConfigurationFile = (new ConfigurationFileFinder)->find($cliConfiguration);

            $this->executeCommandsThatOnlyRequireCliConfiguration($cliConfiguration, $pathToXmlConfigurationFile);

            $xmlConfiguration = $this->loadXmlConfiguration($pathToXmlConfigurationFile);

            $configuration = Registry::init(
                $cliConfiguration,
                $xmlConfiguration
            );

            (new PhpHandler)->handle($configuration->php());

            if ($configuration->hasBootstrap()) {
                $this->loadBootstrapScript($configuration->bootstrap());
            }

            $this->executeCommandsThatRequireCompleteConfiguration($configuration, $cliConfiguration, $xmlConfiguration);

            $testSuite = $this->buildTestSuite($configuration);

            $this->executeCommandsThatRequireCliConfigurationAndTestSuite($cliConfiguration, $testSuite);
            $this->executeHelpCommandWhenThereIsNothingElseToDo($configuration, $testSuite);

            $this->bootstrapExtensions($configuration);

            $runner = new TestRunner;

            $result = $runner->run($configuration, $testSuite);

            $shellExitCode = (new ShellExitCodeCalculator)->calculate(
                $configuration->failOnEmptyTestSuite(),
                $configuration->failOnRisky(),
                $configuration->failOnWarning(),
                $configuration->failOnIncomplete(),
                $configuration->failOnSkipped(),
                $result
            );

            Facade::emitter()->applicationFinished($shellExitCode);

            return $shellExitCode;
        } catch (Throwable $t) {
            $this->exitWithCrashMessage($t);
        }
    }

    private function printVersionString(): void
    {
        if ($this->versionStringPrinted) {
            return;
        }

        print Version::getVersionString() . PHP_EOL . PHP_EOL;

        $this->versionStringPrinted = true;
    }

    private function exitWithCrashMessage(Throwable $t): never
    {
        $message = $t->getMessage();

        if (empty(trim($message))) {
            $message = '(no message)';
        }

        printf(
            '%s%sAn error occurred inside PHPUnit.%s%sMessage:  %s%sLocation: %s:%d%s%s%s%s',
            PHP_EOL,
            PHP_EOL,
            PHP_EOL,
            PHP_EOL,
            $message,
            PHP_EOL,
            $t->getFile(),
            $t->getLine(),
            PHP_EOL,
            PHP_EOL,
            $t->getTraceAsString(),
            PHP_EOL
        );

        exit(Result::CRASH);
    }

    private function exitWithErrorMessage(string $message): never
    {
        $this->printVersionString();

        print $message . PHP_EOL;

        exit(Result::EXCEPTION);
    }

    private function execute(Command\Command $command): never
    {
        $this->printVersionString();

        $result = $command->execute();

        print $result->output();

        exit($result->shellExitCode());
    }

    private function loadBootstrapScript(string $filename): void
    {
        if (!is_readable($filename)) {
            $this->exitWithErrorMessage(
                sprintf(
                    'Cannot open bootstrap script "%s"',
                    $filename
                )
            );
        }

        try {
            include_once $filename;
        } catch (Throwable $t) {
            $this->exitWithErrorMessage(
                sprintf(
                    'Error in bootstrap script: %s:%s%s%s%s',
                    $t::class,
                    PHP_EOL,
                    $t->getMessage(),
                    PHP_EOL,
                    $t->getTraceAsString()
                )
            );
        }

        Facade::emitter()->testRunnerBootstrapFinished($filename);
    }

    private function buildCliConfiguration(array $argv): CliConfiguration
    {
        try {
            $cliConfiguration = (new Builder)->fromParameters($argv);
        } catch (ArgumentsException $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }

        if ($cliConfiguration->hasUnrecognizedOrderBy()) {
            $this->exitWithErrorMessage(
                sprintf(
                    'unrecognized --order-by option: %s',
                    $cliConfiguration->unrecognizedOrderBy()
                )
            );
        }

        return $cliConfiguration;
    }

    private function loadXmlConfiguration(string|false $configurationFile): XmlConfiguration
    {
        if (!$configurationFile) {
            return DefaultConfiguration::create();
        }

        try {
            return (new Loader)->load($configurationFile);
        } catch (Throwable $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }
    }

    private function buildTestSuite(Configuration $configuration): TestSuite
    {
        try {
            return (new TestSuiteBuilder)->build($configuration);
        } catch (Exception $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }
    }

    private function bootstrapExtensions(Configuration $configuration): void
    {
        $extensionBootstrapper = new ExtensionBootstrapper(
            $configuration,
            new ExtensionFacade
        );

        foreach ($configuration->extensionBootstrappers() as $bootstrapper) {
            try {
                $extensionBootstrapper->bootstrap(
                    $bootstrapper['className'],
                    $bootstrapper['parameters']
                );
            } catch (\PHPUnit\Runner\Exception $e) {
                $this->exitWithErrorMessage(
                    sprintf(
                        'Error while bootstrapping extension: %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function executeCommandsThatOnlyRequireCliConfiguration(CliConfiguration $cliConfiguration, string|false $configurationFile): void
    {
        if ($cliConfiguration->generateConfiguration()) {
            $this->execute(new GenerateConfigurationCommand);
        }

        if ($cliConfiguration->migrateConfiguration()) {
            if (!$configurationFile) {
                $this->exitWithErrorMessage('No configuration file found to migrate');
            }

            $this->execute(new MigrateConfigurationCommand(realpath($configurationFile)));
        }

        if ($cliConfiguration->hasAtLeastVersion()) {
            $this->execute(new AtLeastVersionCommand($cliConfiguration->atLeastVersion()));
        }

        if ($cliConfiguration->version()) {
            $this->execute(new ShowVersionCommand);
        }

        if ($cliConfiguration->checkVersion()) {
            $this->execute(new VersionCheckCommand);
        }

        if ($cliConfiguration->help()) {
            $this->execute(new ShowHelpCommand(Result::SUCCESS));
        }
    }

    private function executeCommandsThatRequireCliConfigurationAndTestSuite(CliConfiguration $cliConfiguration, TestSuite $testSuite): void
    {
        if ($cliConfiguration->listGroups()) {
            $this->execute(new ListGroupsCommand($testSuite));
        }

        if ($cliConfiguration->listTests()) {
            $this->execute(new ListTestsAsTextCommand($testSuite));
        }

        if ($cliConfiguration->hasListTestsXml()) {
            $this->execute(
                new ListTestsAsXmlCommand(
                    $cliConfiguration->listTestsXml(),
                    $testSuite
                )
            );
        }
    }

    private function executeCommandsThatRequireCompleteConfiguration(Configuration $configuration, CliConfiguration $cliConfiguration, XmlConfiguration $xmlConfiguration): void
    {
        if ($cliConfiguration->listSuites()) {
            $this->execute(new ListTestSuitesCommand($xmlConfiguration->testSuite()));
        }

        if ($cliConfiguration->warmCoverageCache()) {
            $this->execute(new WarmCodeCoverageCacheCommand($configuration));
        }
    }

    private function executeHelpCommandWhenThereIsNothingElseToDo(Configuration $configuration, TestSuite $testSuite): void
    {
        if ($testSuite->isEmpty() && !$configuration->hasCliArgument() && !$configuration->hasDefaultTestSuite()) {
            $this->execute(new ShowHelpCommand(Result::FAILURE));
        }
    }
}
