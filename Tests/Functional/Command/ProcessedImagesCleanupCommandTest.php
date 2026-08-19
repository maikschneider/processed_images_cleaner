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

    public function setUp(): void
    {
        parent::setUp();

        $command = new ProcessedImagesCleanupCommand(
            new ProcessedFileCleanupService(
                GeneralUtility::makeInstance(ConnectionPool::class),
                new ConfigurationMatcher(),
                GeneralUtility::makeInstance(StorageRepository::class),
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
        self::assertStringContainsString('Expected format', $this->commandTester->getDisplay());
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
        $this->setUpFalScenario(['csm_image1_hash.jpg' => 'PROCESSED'], 'processed_file_deletion.csv');

        $this->commandTester->execute(['--file-uid' => ['1'], '--dry-run' => true]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('dry-run enabled', $display);
        self::assertStringContainsString('[uid: 10, size: 9]', $display);
        self::assertStringContainsString('Dry-run: 1 processed file(s) selected, 1 would be deleted, 9 would be', $display);

        // Neither the database record nor the physical file must be removed
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/processed_file_deletion.csv');
        self::assertFileExists($this->getProcessedFilePathByName('csm_image1_hash.jpg'), 'Physical processed file was removed during dry-run');
    }

    #[Test]
    public function withoutDryRunMatchedProcessedFilesAreDeleted(): void
    {
        $this->setUpFalScenario(['csm_image1_hash.jpg' => 'PROCESSED'], 'processed_file_deletion.csv');

        $this->commandTester->execute(['--file-uid' => ['1']]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('1 processed file(s) selected, 1 deleted, 9 freed.', $display);

        // The processed file record is gone, the original sys_file remains
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TestResults/processed_file_deleted.csv');
        self::assertFileDoesNotExist($this->getProcessedFilePathByName('csm_image1_hash.jpg'), 'Physical processed file was not removed');
    }

    #[Test]
    public function missingPhysicalProcessedFileIsReportedWithSizeZero(): void
    {
        // Only the database records are imported, the physical processed file does not exist
        $this->setUpFalScenario([], 'processed_file_deletion.csv');

        $this->commandTester->execute(['--file-uid' => ['1'], '--dry-run' => true]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('[uid: 10, size: 0]', $display);
        self::assertStringContainsString('Dry-run: 1 processed file(s) selected, 1 would be deleted, 0 would be', $display);
    }

    #[Test]
    public function processedFileRecordIsRemovedWhenThePhysicalFileIsAlreadyGone(): void
    {
        // Only the database records are imported, the physical processed file does not exist
        $this->setUpFalScenario([], 'processed_file_deletion.csv');

        $this->commandTester->execute(['--file-uid' => ['1']]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('1 processed file(s) selected, 1 deleted, 0 freed.', $display);
        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/TestResults/processed_file_deleted.csv');
    }

    #[Test]
    public function processedFilesUsingTheOriginalFileAreSkipped(): void
    {
        $this->setUpFalScenario([], 'processed_file_uses_original.csv');

        $this->commandTester->execute(['--file-uid' => ['1']]);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('1 processed file(s) selected, 0 deleted, 0 freed.', $display);

        // The original file must never be touched
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/image1.jpg');
    }

    #[Test]
    public function nonIntegerLimitOptionShowsErrorAndReturnsInvalid(): void
    {
        $this->commandTester->execute(['--file-uid' => ['1'], '--limit' => 'notAnInteger']);

        self::assertSame(Command::INVALID, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Option --limit must be a positive integer', $this->commandTester->getDisplay());
    }

    #[Test]
    public function limitCapsTheNumberOfDeletedFilesButReportsTheFullSelection(): void
    {
        $this->setUpFalScenario(
            ['csm_image1_hash10.jpg' => 'PROCESSED10', 'csm_image1_hash11.jpg' => 'PROCESSED11'],
            'processed_files_limit.csv'
        );

        $this->commandTester->execute(['--file-uid' => ['1'], '--limit' => '1']);

        $display = $this->commandTester->getDisplay();
        self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
        self::assertStringContainsString('a limit of 1 is applied', $display);
        // Only the size of the deleted file (11 bytes) is reported as freed
        self::assertStringContainsString('2 processed file(s) selected, 1 deleted, 11 freed.', $display);

        // Files are processed ordered by uid, so the lower uid (10) is deleted and uid 11 remains
        self::assertFileDoesNotExist($this->getProcessedFilePathByName('csm_image1_hash10.jpg'), 'First processed file was not removed');
        self::assertFileExists($this->getProcessedFilePathByName('csm_image1_hash11.jpg'), 'Second processed file was removed despite --limit=1');
    }

    /**
     * Creates a default local storage plus the physical original and the given processed files, then imports
     * the matching sys_file / sys_file_processedfile records so the command can resolve and delete real
     * processed files.
     *
     * @param array<string, string> $processedFiles Map of processed file name (within /_processed_/1/) to its content
     * @param string $csvFixture File name of the fixture (within Tests/Functional/Fixtures/) to import
     */
    private function setUpFalScenario(array $processedFiles, string $csvFixture): void
    {
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('Test Storage', 'fileadmin/', 'relative', 'Functional test storage', true);

        $fileadminPath = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadminPath . '/user_upload/');
        GeneralUtility::mkdir_deep($fileadminPath . '/_processed_/1/');
        file_put_contents($fileadminPath . '/user_upload/image1.jpg', 'ORIGINAL');
        foreach ($processedFiles as $name => $content) {
            file_put_contents($this->getProcessedFilePathByName($name), $content);
        }

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/' . $csvFixture);
    }

    private function getProcessedFilePathByName(string $name): string
    {
        return $this->instancePath . '/fileadmin/_processed_/1/' . $name;
    }
}
