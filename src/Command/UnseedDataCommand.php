<?php

namespace App\Command;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:unseed',
    description: 'Purge all seeded database records in safe foreign key order'
)]
class UnseedDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Purging Carpooling Platform Data');

        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Safe deletion order respecting foreign keys
        $tables = [
            'payment',
            'booking',
            'review',
            'notification',
            'waypoint',
            'trip',
            'vehicle',
            'pricing_config',
            'city',
            'user',
        ];

        try {
            if ($platform instanceof MySQLPlatform) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $table) {
                    $connection->executeStatement("TRUNCATE TABLE `{$table}`");
                }
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                // SQLite or PostgreSQL
                foreach ($tables as $table) {
                    $tableName = $table === 'user' ? '"user"' : $table;
                    $connection->executeStatement("DELETE FROM {$tableName}");
                }
            }

            $io->success('All domain tables purged successfully.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to purge data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
