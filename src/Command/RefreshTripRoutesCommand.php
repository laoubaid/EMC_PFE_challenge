<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Trip;
use App\Repository\TripRepository;
use App\Routing\RoutingUnavailableException;
use App\Routing\TripRouteUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:trips:refresh-routes',
    description: 'Recomputes driving distances, durations, arrival times, and GeoJSON geometry using OpenRouteService'
)]
class RefreshTripRoutesCommand extends Command
{
    // 1.6 seconds delay between API calls = ~37.5 requests/min (under 40 requests/min limit)
    private const RATE_LIMIT_DELAY_MICROSECONDS = 1600000;

    public function __construct(
        private readonly TripRepository $tripRepository,
        private readonly TripRouteUpdater $tripRouteUpdater
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Recompute all trips, including those already having route data')
            ->addOption('no-delay', null, InputOption::VALUE_NONE, 'Skip rate limit delay (useful for tests or cached calls)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $all = (bool) $input->getOption('all');
        $noDelay = (bool) $input->getOption('no-delay');

        $allTrips = $this->tripRepository->findAll();
        /** @var list<Trip> $tripsToRefresh */
        $tripsToRefresh = [];

        foreach ($allTrips as $trip) {
            if ($all) {
                $tripsToRefresh[] = $trip;
                continue;
            }

            // Check if any waypoint has null duration, or if trip has no route geometry
            $needsRefresh = ($trip->getRouteGeometry() === null);
            if (!$needsRefresh) {
                foreach ($trip->getWaypoints() as $wp) {
                    if ($wp->getDurationFromOriginMin() === null) {
                        $needsRefresh = true;
                        break;
                    }
                }
            }

            if ($needsRefresh) {
                $tripsToRefresh[] = $trip;
            }
        }

        $total = count($tripsToRefresh);
        if ($total === 0) {
            $io->success('All trips already have up-to-date route durations and geometry. Nothing to backfill.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Backfilling route data for %d trip(s) via OpenRouteService...', $total));

        $successCount = 0;
        $failCount = 0;

        foreach ($tripsToRefresh as $index => $trip) {
            $tripId = $trip->getId() ?? 0;
            $originName = $trip->getOriginCity()?->getName() ?? 'Start';
            $destName = $trip->getDestinationCity()?->getName() ?? 'End';

            $io->write(sprintf('[%d/%d] Trip #%d (%s -> %s)... ', $index + 1, $total, $tripId, $originName, $destName));

            try {
                $this->tripRouteUpdater->refresh($trip);

                $destWp = $trip->getDestinationWaypoint();
                $dist = $destWp ? $destWp->getDistanceFromOriginKm() : '0.0';
                $duration = $destWp?->getDurationFromOriginMin() ?? 0;
                $stopsCount = count($trip->getOrderedWaypoints());

                $io->writeln(sprintf(
                    '<info>OK</info> (%d stops, %s km, %d min driving time)',
                    $stopsCount,
                    $dist,
                    $duration
                ));
                $successCount++;
            } catch (RoutingUnavailableException $e) {
                $io->writeln(sprintf('<comment>SKIPPED</comment> (ORS unavailable: %s)', $e->getMessage()));
                $failCount++;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<error>ERROR</error> (%s)', $e->getMessage()));
                $failCount++;
            }

            // Delay between requests to stay under 40 requests/minute
            if (!$noDelay && $index < $total - 1) {
                usleep(self::RATE_LIMIT_DELAY_MICROSECONDS);
            }
        }

        $io->newLine();
        $io->success(sprintf('Backfill complete! %d refreshed successfully, %d failed/skipped.', $successCount, $failCount));

        return Command::SUCCESS;
    }
}
