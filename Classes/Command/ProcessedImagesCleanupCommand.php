<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Command;

use Derhansen\ProcessedImagesCleaner\Dto\CleanupDemand;
use Derhansen\ProcessedImagesCleaner\Service\ProcessedFileCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

#[AsCommand(
    'cleanup:processedimages',
    'Clean up processed image files',
)]
final class ProcessedImagesCleanupCommand extends Command
{
    private const FORMAT_LABELS = '|k|M|G|T|P|E|Z|Y';

    public function __construct(
        private readonly ProcessedFileCleanupService $cleanupService,
        private ProcessedFileRepository $processedFileRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not perform any deletions but only show what will be deleted.')
            ->addOption('storage', 's', InputOption::VALUE_OPTIONAL, 'Storage UID of the original files')
            ->addOption('folder', 'f', InputOption::VALUE_OPTIONAL, 'Folder identifier within the storage, e.g. "user_upload/"')
            ->addOption('file-uid', 'u', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'UID of an original sys_file (repeatable)')
            ->addOption('identifier', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Full original file identifier incl. path, e.g. "/user_upload/foo/bar/image.jpg" (repeatable)')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Configuration equality filter "key=value" (repeatable), e.g. -c width=1000 -c maxWidth=1800 -c cropVariant=default. Numeric values also match TypoScript modifiers ("800" matches "800m"); use "key=null" for unset values.')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of processed files to delete. Files are processed ordered by uid. If omitted, all matching files are deleted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Processed images cleanup');

        try {
            $demand = $this->createDemand($input);
            $limit = $this->limitOption($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        if (!$demand->hasFileFilter() && !$demand->hasConfigurationFilter()) {
            $io->error('Refusing to run without any filter. Provide at least one file filter (--storage, --folder, --file-uid, --identifier) or a configuration filter (--config).');
            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $io->info('No files will be deleted. dry-run enabled.');
        }

        $matches = $this->cleanupService->findDemanded($demand);
        $total = count($matches);

        if ($total === 0) {
            $io->success('No processed files match the given criteria.');
            return Command::SUCCESS;
        }

        if ($limit !== null && $limit < $total) {
            $matches = array_slice($matches, 0, $limit);
            $io->note(sprintf(
                '%d processed file(s) match, but a limit of %d is applied. Only the first %d (ordered by uid) will be %s.',
                $total,
                $limit,
                $limit,
                $dryRun ? 'reported' : 'deleted'
            ));
        }

        $selected = count($matches);
        $deletedFiles = 0;
        $failedFiles = 0;
        $totalBytes = 0;
        foreach ($matches as $row) {
            $processedFile = $this->processedFileRepository->findByUid((int)$row['uid']);
            $processedFileSize = $processedFile->exists() ? $processedFile->getSize() : 0;
            $totalBytes += $processedFileSize;

            if (!$dryRun) {
                try {
                    $this->cleanupService->delete($processedFile);
                    $deletedFiles++;
                } catch (\Throwable $e) {
                    $failedFiles++;
                    $io->warning(sprintf('Could not delete processed file uid %d: %s', (int)$row['uid'], $e->getMessage()));
                }
            }

            $io->writeln(
                sprintf('Deleted processed file "%s" [uid: %d, size: %s]', $row['identifier'], $row['uid'],  GeneralUtility::formatSize($processedFileSize, self::FORMAT_LABELS))
            );
        }

        $io->newLine();

        $deletedBytes = GeneralUtility::formatSize($totalBytes, self::FORMAT_LABELS);
        if ($dryRun) {
            $io->success(sprintf('Dry-run: %d processed file(s) selected, %d would be deleted, %s would be freed.', $total, $selected, $deletedBytes));
            return Command::SUCCESS;
        }

        if ($failedFiles > 0) {
            $io->warning(sprintf('%d processed file(s) selected, %d deleted (%s freed), %d failed. See log for details.', $total, $deletedFiles, $deletedBytes, $failedFiles));
            return Command::FAILURE;
        }

        $io->success(sprintf('%d processed file(s) selected, %d deleted, %s freed.', $total, $deletedFiles, $deletedBytes));
        return Command::SUCCESS;
    }

    private function createDemand(InputInterface $input): CleanupDemand
    {
        return new CleanupDemand(
            $this->intOption($input, 'storage'),
            $this->stringOption($input, 'folder'),
            $this->intListOption($input, 'file-uid'),
            $input->getOption('identifier'),
            $this->parseConfig($input->getOption('config')),
        );
    }

    /**
     * @param string[] $pairs
     * @return array<string, string>
     */
    private function parseConfig(array $pairs): array
    {
        $configuration = [];
        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                throw new \InvalidArgumentException(sprintf('Invalid --config value "%s". Expected format "key=value".', $pair));
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            if ($key === '') {
                throw new \InvalidArgumentException(sprintf('Invalid --config value "%s". The key must not be empty.', $pair));
            }
            $configuration[$key] = $value;
        }

        return $configuration;
    }

    private function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!MathUtility::canBeInterpretedAsInteger($value)) {
            throw new \InvalidArgumentException(sprintf('Option --%s must be an integer, "%s" given.', $name, $value));
        }

        return (int)$value;
    }

    /**
     * @return int[]
     */
    private function intListOption(InputInterface $input, string $name): array
    {
        $values = [];
        foreach ($input->getOption($name) as $value) {
            if (!MathUtility::canBeInterpretedAsInteger($value)) {
                throw new \InvalidArgumentException(sprintf('Option --%s must contain integers, "%s" given.', $name, $value));
            }
            $values[] = (int)$value;
        }

        return $values;
    }

    private function limitOption(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if ($value === null) {
            return null;
        }
        if (!MathUtility::canBeInterpretedAsInteger($value) || (int)$value < 1) {
            throw new \InvalidArgumentException(sprintf('Option --limit must be a positive integer, "%s" given.', $value));
        }

        return (int)$value;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return $value === null ? null : (string)$value;
    }
}
