<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Tests\Functional\Service;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Derhansen\ProcessedImagesCleaner\Service\ConfigurationMatcher;
use Derhansen\ProcessedImagesCleaner\Service\ProcessedFileCleanupService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
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
            GeneralUtility::makeInstance(StorageRepository::class),
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

    #[Test]
    public function getProcessedFileSizeReturnsSizeOfPhysicalFileInRelativeLocalStorage(): void
    {
        $storageUid = $this->createLocalStorage('fileadmin/', 'relative');
        $this->createPhysicalFile('fileadmin/_processed_/1/csm_image1_hash.jpg', 'PROCESSED');

        $size = $this->subject->getProcessedFileSize([
            'storage' => $storageUid,
            'identifier' => '/_processed_/1/csm_image1_hash.jpg',
        ]);

        self::assertSame(9, $size);
    }

    #[Test]
    public function getProcessedFileSizeReturnsSizeOfPhysicalFileInAbsoluteLocalStorage(): void
    {
        $storageUid = $this->createLocalStorage($this->instancePath . '/fileadmin/', 'absolute');
        $this->createPhysicalFile('fileadmin/_processed_/1/csm_image1_hash.jpg', 'PROCESSED FILE');

        $size = $this->subject->getProcessedFileSize([
            'storage' => $storageUid,
            'identifier' => '/_processed_/1/csm_image1_hash.jpg',
        ]);

        self::assertSame(14, $size);
    }

    #[Test]
    public function getProcessedFileSizeReturnsSizeOfPhysicalFileInFallbackStorage(): void
    {
        // Storage 0 is the TYPO3 fallback storage, its identifiers are relative to the public path
        $this->createPhysicalFile('typo3temp/assets/_processed_/1/csm_image1_hash.jpg', 'PROCESSED');

        $size = $this->subject->getProcessedFileSize([
            'storage' => 0,
            'identifier' => '/typo3temp/assets/_processed_/1/csm_image1_hash.jpg',
        ]);

        self::assertSame(9, $size);
    }

    #[Test]
    public function getProcessedFileSizeReturnsZeroWhenPhysicalFileDoesNotExist(): void
    {
        $storageUid = $this->createLocalStorage('fileadmin/', 'relative');

        $size = $this->subject->getProcessedFileSize([
            'storage' => $storageUid,
            'identifier' => '/_processed_/1/csm_image1_gone.jpg',
        ]);

        self::assertSame(0, $size);
    }

    #[Test]
    public function getProcessedFileSizeReturnsZeroForUnknownStorage(): void
    {
        $size = $this->subject->getProcessedFileSize([
            'storage' => 99,
            'identifier' => '/_processed_/1/csm_image1_hash.jpg',
        ]);

        self::assertSame(0, $size);
    }

    #[Test]
    public function getProcessedFileSizeReturnsZeroForEmptyIdentifier(): void
    {
        $storageUid = $this->createLocalStorage('fileadmin/', 'relative');

        self::assertSame(0, $this->subject->getProcessedFileSize(['storage' => $storageUid, 'identifier' => '']));
    }

    #[Test]
    public function getProcessedFileSizeReturnsZeroForStorageWithUnknownDriver(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_storage');
        $connection->insert(
            'sys_file_storage',
            ['pid' => 0, 'name' => 'Remote storage', 'driver' => 'NonExistingDriver', 'configuration' => '', 'is_online' => 1]
        );

        $size = $this->subject->getProcessedFileSize([
            'storage' => (int)$connection->lastInsertId(),
            'identifier' => '/_processed_/1/csm_image1_hash.jpg',
        ]);

        self::assertSame(0, $size);
    }

    #[Test]
    public function deleteRemovesTheDatabaseRecordAndThePhysicalFile(): void
    {
        $processedFile = $this->setUpProcessedFile('PROCESSED');

        $this->subject->delete($processedFile, 9);

        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_processed_/csm_image1_10.jpg');
        self::assertSame(0, $this->countProcessedFileRecords(10));
    }

    #[Test]
    public function deleteRemovesOnlyTheDatabaseRecordWhenThePhysicalFileIsMissing(): void
    {
        // The physical file is not created, so the fast path without the FAL API is used
        $processedFile = $this->setUpProcessedFile(null);

        $this->subject->delete($processedFile, 0);

        self::assertSame(0, $this->countProcessedFileRecords(10));
    }

    #[Test]
    public function deleteRemovesAnEmptyPhysicalFileDespiteTheReportedSizeOfZero(): void
    {
        $processedFile = $this->setUpProcessedFile('');

        $this->subject->delete($processedFile, 0);

        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_processed_/csm_image1_10.jpg');
        self::assertSame(0, $this->countProcessedFileRecords(10));
    }

    /**
     * Creates the storage and the physical original file for processed file 10 of the fixture and returns the
     * matching ProcessedFile object. The physical processed file is only created if content is given.
     */
    private function setUpProcessedFile(?string $processedFileContent): ProcessedFile
    {
        $this->createLocalStorage('fileadmin/', 'relative');
        $this->createPhysicalFile('fileadmin/user_upload/image1.jpg', 'ORIGINAL');
        if ($processedFileContent !== null) {
            $this->createPhysicalFile('fileadmin/_processed_/csm_image1_10.jpg', $processedFileContent);
        }

        return GeneralUtility::makeInstance(ProcessedFileRepository::class)->findByUid(10);
    }

    private function countProcessedFileRecords(int $uid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_processedfile');

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_file_processedfile')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    private function createLocalStorage(string $basePath, string $pathType): int
    {
        GeneralUtility::mkdir_deep($pathType === 'relative' ? $this->instancePath . '/' . $basePath : $basePath);

        return GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('Test Storage', $basePath, $pathType, 'Functional test storage', true);
    }

    private function createPhysicalFile(string $relativePath, string $content): void
    {
        $absolutePath = $this->instancePath . '/' . $relativePath;
        GeneralUtility::mkdir_deep(dirname($absolutePath));
        file_put_contents($absolutePath, $content);
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
