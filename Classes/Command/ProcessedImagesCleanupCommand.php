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
use TYPO3\CMS\Core\Utility\MathUtility;

#[AsCommand(
    'cleanup:processedimages',
    'Clean up processed image files',
)]
final class ProcessedImagesCleanupCommand extends Command
{
    public function __construct(
        private readonly ProcessedFileCleanupService $cleanupService,
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
            ->addOption('identifier', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Full original file identifier incl. path, e.g. "/user_upload/foo/bar/image.jpg" (repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Processed images cleanup');

        try {
            $demand = $this->createDemand($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        if (!$demand->hasFileFilter()) {
            $io->error('Refusing to run without any file filter.');
            return Command::INVALID;
        }

        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $io->writeln('No files will be deleted. dry-run enabled.');
        }

        $matches = $this->cleanupService->findDemanded($demand);
        $total = count($matches);

        if ($total === 0) {
            $io->success('No processed files match the given criteria.');
            return Command::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($matches as $row) {
            $io->writeln('Test: ' . $row['identifier'] . ' - ' . $row['uid']);

            if ($dryRun) {
                continue;
            }

            try {
                //$this->cleanupService->delete((int)$row['uid']);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                $io->warning(sprintf('Could not delete processed file uid %d: %s', (int)$row['uid'], $e->getMessage()));
            }
        }

        $io->newLine();

        if ($dryRun) {
            $io->warning(sprintf('Dry-run: %d processed file(s) match.', $total));
            return Command::SUCCESS;
        }

        if ($failed > 0) {
            $io->warning(sprintf('Deleted %d processed file(s), %d failed. See log for details.', $deleted, $failed));
            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d processed file(s).', $deleted));
        return Command::SUCCESS;
    }

    private function createDemand(InputInterface $input): CleanupDemand
    {
        return new CleanupDemand(
            $this->intOption($input, 'storage'),
            $this->stringOption($input, 'folder'),
            $this->intListOption($input, 'file-uid'),
            $input->getOption('identifier')
        );
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

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return $value === null ? null : (string)$value;
    }
}
