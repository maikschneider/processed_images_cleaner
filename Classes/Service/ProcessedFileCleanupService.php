<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Service;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\ProcessedFile;

/**
 * Finds and deletes sys_file_processedfile records matching a CleanupCriteria.
 */
final readonly class ProcessedFileCleanupService
{
    private const TABLE = 'sys_file_processedfile';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ConfigurationMatcher $configurationMatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * Returns all matching processed file rows. Configuration/crop-variant filters are evaluated per row after
     * unserializing the configuration.
     */
    public function findDemanded(CleanupDemand $criteria): array
    {
        $rows = $this->createQuery($criteria)->executeQuery()->fetchAllAssociative();

        if (!$criteria->hasConfigurationFilter()) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->configurationMatches($row, $criteria),
        ));
    }

    public function delete(ProcessedFile $processedFile): void
    {
        $uid = $processedFile->getUid();
        $processedFile->getStorage()->setEvaluatePermissions(false);
        $processedFile->delete(true);

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

        return $this->configurationMatcher->matches($configuration, $criteria->configuration);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unserializeConfiguration(string $configuration): ?array
    {
        if ($configuration === '') {
            return null;
        }
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

        $queryBuilder->join(
            'p',
            'sys_file',
            'f',
            $expr->eq('f.uid', $queryBuilder->quoteIdentifier('p.original'))
        );

        $conditions = [
            // Only process files whose original is an image
            $expr->eq(
                'f.type',
                $queryBuilder->createNamedParameter(FileType::IMAGE->value, Connection::PARAM_INT)
            ),
            // Never touch "uses original file" placeholder rows. When TYPO3 decides no processing is
            // needed it stores a row with an empty identifier (and no name) to cache that decision;
            // no physical file exists, so there is nothing to clean up.
            $expr->isNotNull('p.identifier'),
            $expr->neq('p.identifier', $queryBuilder->createNamedParameter('')),
        ];

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

        $queryBuilder->where(...$conditions);

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
