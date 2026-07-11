<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Tests\Functional\Command;

use Derhansen\ProcessedImagesCleaner\Command\ProcessedImagesCleanupCommand;
use Derhansen\ProcessedImagesCleaner\Service\ConfigurationMatcher;
use Derhansen\ProcessedImagesCleaner\Service\ProcessedFileCleanupService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProcessedImagesCleanupCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3conf/ext/processed_images_cleaner'];
    private CommandTester $commandTester;
    private const PROCESSED_IDENTIFIER = '/_processed_/1/csm_image1_hash.jpg';

    public function setUp(): void
    {
        parent::setUp();

        $command = new ProcessedImagesCleanupCommand(
            new ProcessedFileCleanupService(
                GeneralUtility::makeInstance(ConnectionPool::class),
                new ConfigurationMatcher(),
                new NullLogger(),
            ),
            GeneralUtility::makeInstance(ProcessedFileRepository::class),
        );

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function withoutAnyFilterCommandRefusesToRunAndReturnsInvalid(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Refusing to run without any filter', $this->commandTester->getDisplay());
    }

    #[Test]
    public function invalidConfigFilterShowsErrorAndReturnsInvalid(): void
    {
        $this->commandTester->execute(['--config' => ['widthWithoutValue']]);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Expected format"', $this->commandTester->getDisplay());
        self::assertStringContainsString('"key=value"', $this->commandTester->getDisplay());
    }

    #[Test]
    public function nonIntegerStorageOptionShowsErrorAndReturnsInvalid(): void
    {
        $this->commandTester->execute(['--storage' => 'notAnInteger']);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Option --storage must be an integer', $this->commandTester->getDisplay());
    }

    #[Test]
    public function whenNoProcessedFilesMatchASuccessMessageIsShown(): void
    {
        $this->commandTester->execute(['--file-uid' => ['999']]);

        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('No processed files match the given criteria.', $this->commandTester->getDisplay());
    }

    #[Test]
    public function dryRunReportsMatchesButDoesNotDeleteFiles(): void
    {
        $this->setUpFalScenario();

        $this->commandTester->execute(['--file-uid' => ['1'], '--dry-run' => true]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('dry-run enabled', $display);
        self::assertStringContainsString('Dry-run: 1 processed file(s) match', $display);

        // Neither the database record nor the physical file must be removed
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/processed_file_deletion.csv');
        self::assertFileExists($this->getProcessedFilePath(), 'Physical processed file was removed during dry-run');
    }

    #[Test]
    public function withoutDryRunMatchedProcessedFilesAreDeleted(): void
    {
        $this->setUpFalScenario();

        $this->commandTester->execute(['--file-uid' => ['1']]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Deleted 1 processed file(s)', $display);

        // The processed file record is gone, the original sys_file remains
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TestResults/processed_file_deleted.csv');
        self::assertFileDoesNotExist($this->getProcessedFilePath(), 'Physical processed file was not removed');
    }

    /**
     * Creates a default local storage plus the physical original and processed files, then imports the
     * matching sys_file / sys_file_processedfile records so the command can resolve and delete a real
     * processed file.
     */
    private function setUpFalScenario(): void
    {
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('Test Storage', 'fileadmin/', 'relative', 'Functional test storage', true);

        $fileadminPath = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadminPath . '/user_upload/');
        GeneralUtility::mkdir_deep($fileadminPath . '/_processed_/1/');
        file_put_contents($fileadminPath . '/user_upload/image1.jpg', 'ORIGINAL');
        file_put_contents($this->getProcessedFilePath(), 'PROCESSED');

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/processed_file_deletion.csv');
    }

    private function getProcessedFilePath(): string
    {
        return $this->instancePath . '/fileadmin' . self::PROCESSED_IDENTIFIER;
    }
}
