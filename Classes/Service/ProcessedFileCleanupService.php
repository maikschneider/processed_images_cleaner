<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Service;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;

/**
 * Finds and deletes sys_file_processedfile records matching a CleanupCriteria.
 */
final readonly class ProcessedFileCleanupService
{
    private const TABLE = 'sys_file_processedfile';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ProcessedFileRepository $processedFileRepository,
        private ConfigurationMatcher $configurationMatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Streams all matching processed file rows. Configuration/crop-variant filters
     * are evaluated per row after unserializing the configuration.
     */
    public function findDemanded(CleanupDemand $criteria): array
    {
        $result = $this->createQuery($criteria)->executeQuery()->fetchAllAssociative();

        return $result;
    }

    public function delete(int $uid): void
    {
        $processedFile = $this->processedFileRepository->findByUid($uid);
        $processedFile->getStorage()->setEvaluatePermissions(false);
        $processedFile->delete(true);

        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(
            self::TABLE,
            ['uid' => $uid],
            ['uid' => Connection::PARAM_INT]
        );

        $this->logger->info('Deleted processed file with uid {uid}', ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configurationMatches(array $row, CleanupDemand $criteria): bool
    {
        $configuration = $this->unserializeConfiguration((string)($row['configuration'] ?? ''));
        if ($configuration === null) {
            return false;
        }

        return $this->configurationMatcher->matches($configuration, $criteria->configuration, $criteria->cropVariant);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unserializeConfiguration(string $configuration): ?array
    {
        if ($configuration === '') {
            return null;
        }

        // Read-only matching: never allow object instantiation from stored data.
        $data = @unserialize($configuration, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    private function createQuery(CleanupDemand $demand): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr = $queryBuilder->expr();

        $queryBuilder
            ->select('p.*')
            ->from(self::TABLE, 'p')
            ->orderBy('p.uid');

        $conditions = [];

        $needsOriginalJoin = $demand->storage !== null
            || $demand->folder !== null
            || $demand->identifiers !== [];
        if ($needsOriginalJoin) {
            $queryBuilder->join(
                'p',
                'sys_file',
                'f',
                $expr->eq('f.uid', $queryBuilder->quoteIdentifier('p.original'))
            );
            
            // @todo: Make sure to only select image files (field: extension)
        }

        if ($demand->storage !== null) {
            $conditions[] = $expr->eq(
                'f.storage',
                $queryBuilder->createNamedParameter($demand->storage, Connection::PARAM_INT)
            );
        }

        if ($demand->folder !== null) {
            $conditions[] = $expr->like(
                'f.identifier',
                $queryBuilder->createNamedParameter(
                    $queryBuilder->escapeLikeWildcards($this->normalizeFolder($demand->folder)) . '%'
                )
            );
        }

        if ($demand->identifiers !== []) {
            $identifiers = array_map($this->normalizeIdentifier(...), $demand->identifiers);
            $conditions[] = $expr->in(
                'f.identifier',
                $queryBuilder->createNamedParameter($identifiers, ArrayParameterType::STRING)
            );
        }

        if ($demand->fileUids !== []) {
            $conditions[] = $expr->in(
                'p.original',
                $queryBuilder->createNamedParameter($demand->fileUids, ArrayParameterType::INTEGER)
            );
        }

        if ($conditions !== []) {
            $queryBuilder->where(...$conditions);
        }

        return $queryBuilder;
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim(trim($folder), '/');

        return $folder === '' ? '/' : '/' . $folder . '/';
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return '/' . ltrim(trim($identifier), '/');
    }
}
