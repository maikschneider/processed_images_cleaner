<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Tests\Functional\Service;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Derhansen\ProcessedImagesCleaner\Service\ConfigurationMatcher;
use Derhansen\ProcessedImagesCleaner\Service\ProcessedFileCleanupService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ProcessedFileCleanupServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3conf/ext/processed_images_cleaner'];
    protected ProcessedFileCleanupService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new ProcessedFileCleanupService(
            GeneralUtility::makeInstance(ConnectionPool::class),
            new ConfigurationMatcher(),
            new NullLogger(),
        );

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/processed_files.csv');
    }

    #[Test]
    public function withoutFilterAllImageProcessedFilesWithIdentifierAreReturned(): void
    {
        // Processed file 14 (non-image original) and 15 (empty identifier) must never be returned
        $result = $this->subject->findDemanded(new CleanupDemand());

        self::assertSame([10, 11, 12, 13], $this->getUids($result));
    }

    #[Test]
    public function filterByStorageReturnsOnlyProcessedFilesOfThatStorage(): void
    {
        $result = $this->subject->findDemanded(new CleanupDemand(storage: 1));

        self::assertSame([10, 11, 12], $this->getUids($result));
    }

    #[Test]
    public function filterByFolderReturnsOnlyProcessedFilesBelowThatFolder(): void
    {
        $result = $this->subject->findDemanded(new CleanupDemand(folder: 'user_upload/sub/'));

        self::assertSame([12], $this->getUids($result));
    }

    #[Test]
    public function filterByFileUidReturnsOnlyProcessedFilesOfThatOriginal(): void
    {
        $result = $this->subject->findDemanded(new CleanupDemand(fileUids: [1]));

        self::assertSame([10, 11], $this->getUids($result));
    }

    #[Test]
    public function filterByIdentifierNormalizesLeadingSlashAndMatchesExactOriginal(): void
    {
        // Identifier is passed without a leading slash and must still match "/user_upload/image1.jpg"
        $result = $this->subject->findDemanded(new CleanupDemand(identifiers: ['user_upload/image1.jpg']));

        self::assertSame([10, 11], $this->getUids($result));
    }

    #[Test]
    public function filterByConfigurationWidthMatchesNumericAndTypoScriptModifierValues(): void
    {
        // width=800 must match the stored TypoScript modifier value "800m"
        $result = $this->subject->findDemanded(new CleanupDemand(configuration: ['width' => '800']));
        self::assertSame([11], $this->getUids($result));

        // width=200 must match the plain integer values
        $result = $this->subject->findDemanded(new CleanupDemand(configuration: ['width' => '200']));
        self::assertSame([10, 12, 13], $this->getUids($result));
    }

    #[Test]
    public function filterByCropVariantReturnsOnlyProcessedFilesWithThatVariant(): void
    {
        $result = $this->subject->findDemanded(new CleanupDemand(configuration: ['cropVariant' => 'default']));

        self::assertSame([12], $this->getUids($result));
    }

    #[Test]
    public function filterByNullConfigurationValueMatchesUnsetKeys(): void
    {
        // Only processed file 10 has a "height" key, so height=null must match the others
        $result = $this->subject->findDemanded(new CleanupDemand(configuration: ['height' => 'null']));

        self::assertSame([11, 12, 13], $this->getUids($result));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function getUids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int)$row['uid'], $rows);
    }
}
