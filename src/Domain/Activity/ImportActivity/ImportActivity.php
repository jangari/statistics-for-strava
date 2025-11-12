<?php

declare(strict_types=1);

namespace App\Domain\Activity\ImportActivity;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\CQRS\Command\DomainCommand;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ImportActivity extends DomainCommand
{
    public function __construct(
        private ActivityId $activityId,
        private OutputInterface $output,
    ) {
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }
}
