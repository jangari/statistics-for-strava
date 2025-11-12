<?php

declare(strict_types=1);

namespace App\Domain\Activity\ImportActivity;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ImportActivities\ActivitiesToSkipDuringImport;
use App\Domain\Activity\ImportActivities\ActivityImageDownloader;
use App\Domain\Activity\ImportActivities\ActivityVisibilitiesToImport;
use App\Domain\Activity\ImportActivities\SkipActivitiesRecordedBefore;
use App\Domain\Activity\Lap\ActivityLapManager;
use App\Domain\Activity\SaveActivity\ActivityActivityManager;
use App\Domain\Activity\Stream\StreamActivityManager;
use App\Domain\Segment\SegmentEffort\DeleteActivitySegmentEfforts\SegmentEffortsWereDeleted;
use App\Domain\Segment\SegmentEffort\SegmentEffortActivityManager;
use App\Domain\Strava\Strava;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Eventing\EventBus;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ImportActivityCommandHandler implements CommandHandler
{
    public function __construct(
        private Strava $strava,
        private ActivityRepository $activityRepository,
        private ActivityActivityManager $activityManager,
        private StreamActivityManager $streamManager,
        private ActivityLapManager $lapManager,
        private SegmentEffortActivityManager $segmentEffortManager,
        private ActivityImageDownloader $activityImageDownloader,
        private ActivityVisibilitiesToImport $activityVisibilitiesToImport,
        private ActivitiesToSkipDuringImport $activitiesToSkip,
        private SkipActivitiesRecordedBefore $skipActivitiesRecordedBefore,
        private EventBus $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportActivity $command): void
    {
        $activityId = $command->getActivityId();
        $output = $command->getOutput();

        $this->strava->setConsoleOutput($output);
        $this->activityManager->setConsoleOutput($output);
        $this->streamManager->setConsoleOutput($output);
        $this->lapManager->setConsoleOutput($output);
        $this->segmentEffortManager->setConsoleOutput($output);
        $this->activityImageDownloader->setConsoleOutput($output);

        $output->writeln(sprintf('Importing single activity: %s', $activityId));

        try {
            $stravaActivity = $this->strava->getActivity($activityId);

            // Check filters
            if (!$this->activityVisibilitiesToImport->shouldImport($stravaActivity['visibility'])) {
                $output->writeln(sprintf(
                    'Skipping activity %s: visibility "%s" should not be imported',
                    $activityId,
                    $stravaActivity['visibility']
                ));

                return;
            }

            if ($this->activitiesToSkip->shouldSkip($activityId->toUnprefixedString())) {
                $output->writeln(sprintf('Skipping activity %s: configured to be skipped', $activityId));

                return;
            }

            if ($this->skipActivitiesRecordedBefore->shouldSkip($stravaActivity['start_date_local'])) {
                $output->writeln(sprintf('Skipping activity %s: recorded before %s', $activityId, $this->skipActivitiesRecordedBefore));

                return;
            }

            // Check if activity already exists to dispatch update event
            if ($this->activityRepository->find($activityId)) {
                $output->writeln(sprintf('Activity %s already exists, updating...', $activityId));
                $this->eventBus->dispatch(new SegmentEffortsWereDeleted($activityId));
            }

            // 1. Save Activity
            $this->activityManager->save($stravaActivity);

            // 2. Save Streams
            $streams = $this->strava->getAllActivityStreams($activityId);
            $this->streamManager->save($activityId, $streams);

            // 3. Save Laps
            $this->lapManager->save($activityId, $stravaActivity['laps']);

            // 4. Save Segment Efforts
            $this->segmentEffortManager->save($activityId, $stravaActivity['segment_efforts']);

            // 5. Download Photos
            $photos = $this->strava->getActivityPhotos($activityId);
            $this->activityImageDownloader->downloadImages($activityId, $photos);

            $output->writeln(sprintf('Successfully imported activity: %s', $activityId));
        } catch (ClientException | RequestException $e) {
            if (404 === $e->getCode()) {
                $this->logger->info(sprintf('Activity %s not found or private, skipping', $activityId));
                $output->writeln(sprintf('<comment>Activity %s not found or private, skipping</comment>', $activityId));

                return;
            }

            $this->logger->error(sprintf('Error importing activity %s: %s', $activityId, $e->getMessage()));
            $output->writeln(sprintf('<error>Error importing activity %s: %s</error>', $activityId, $e->getMessage()));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error importing activity %s: %s', $activityId, $e->getMessage()));
            $output->writeln(sprintf('<error>Error importing activity %s: %s</error>', $activityId, $e->getMessage()));
        }
    }
}
