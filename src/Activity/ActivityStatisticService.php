<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Activity;

use App\Entity\Activity;
use App\Event\ActivityStatisticEvent;
use App\Model\ActivityBudgetStatisticModel;
use App\Model\ActivityStatistic;
use App\Repository\ActivityRepository;
use App\Repository\TimesheetRepository;
use App\Timesheet\DateTimeFactory;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @final
 */
class ActivityStatisticService
{
    private $activityRepository;
    private $timesheetRepository;
    private $dispatcher;

    public function __construct(ActivityRepository $activityRepository, TimesheetRepository $timesheetRepository, EventDispatcherInterface $dispatcher)
    {
        $this->activityRepository = $activityRepository;
        $this->timesheetRepository = $timesheetRepository;
        $this->dispatcher = $dispatcher;
    }

    public function getActivityStatistics(Activity $activity, ?DateTime $begin = null, ?DateTime $end = null): ActivityStatistic
    {
        $statistics = $this->getBudgetStatistic([$activity], $begin, $end);
        $event = new ActivityStatisticEvent($activity, array_pop($statistics), $begin, $end);
        $this->dispatcher->dispatch($event);

        return $event->getStatistic();
    }

    public function getBudgetStatisticModel(Activity $activity, DateTime $today): ActivityBudgetStatisticModel
    {
        $stats = new ActivityBudgetStatisticModel($activity);
        $stats->setStatisticTotal($this->getActivityStatistics($activity));

        $begin = null;
        $end = $today;

        if ($activity->isMonthlyBudget()) {
            $dateFactory = new DateTimeFactory($today->getTimezone());
            $begin = $dateFactory->getStartOfMonth($today);
            $end = $dateFactory->getEndOfMonth($today);
        }

        $stats->setStatistic($this->getActivityStatistics($activity, $begin, $end));

        return $stats;
    }

    /**
     * @param Activity[] $activities
     * @param DateTime|null $begin
     * @param DateTime|null $end
     * @return array<int, ActivityStatistic>
     */
    private function getBudgetStatistic(array $activities, ?DateTime $begin = null, ?DateTime $end = null): array
    {
        $statistics = [];
        foreach ($activities as $activity) {
            $statistics[$activity->getId()] = new ActivityStatistic();
        }

        $qb = $this->createStatisticQueryBuilder($activities, $begin, $end);

        $result = $qb->getQuery()->getResult();

        if (null !== $result) {
            foreach ($result as $resultRow) {
                $statistic = $statistics[$resultRow['id']];
                $statistic->setDuration($statistic->getDuration() + $resultRow['duration']);
                $statistic->setRate($statistic->getRate() + $resultRow['rate']);
                $statistic->setInternalRate($statistic->getInternalRate() + $resultRow['internalRate']);
                $statistic->setCounter($statistic->getCounter() + $resultRow['counter']);
                if ($resultRow['billable']) {
                    $statistic->setDurationBillable($resultRow['duration']);
                    $statistic->setRateBillable($resultRow['rate']);
                    $statistic->setInternalRateBillable($resultRow['internalRate']);
                    $statistic->setCounterBillable($resultRow['counter']);
                }
            }
        }

        return $statistics;
    }

    private function createStatisticQueryBuilder(array $activities, DateTime $begin = null, ?DateTime $end = null): QueryBuilder
    {
        $qb = $this->timesheetRepository->createQueryBuilder('t');
        $qb
            ->select('IDENTITY(t.activity) AS id')
            ->addSelect('COALESCE(SUM(t.duration), 0) as duration')
            ->addSelect('COALESCE(SUM(t.rate), 0) as rate')
            ->addSelect('COALESCE(SUM(t.internalRate), 0) as internalRate')
            ->addSelect('COUNT(t.id) as counter')
            ->addSelect('t.billable as billable')
            ->andWhere($qb->expr()->isNotNull('t.end'))
            ->groupBy('id')
            ->addGroupBy('billable')
            ->andWhere($qb->expr()->in('t.activity', ':activity'))
            ->setParameter('activity', $activities)
        ;

        if ($begin !== null) {
            $qb
                ->andWhere($qb->expr()->gte('t.begin', ':begin'))
                ->setParameter('begin', $begin, Types::DATETIME_MUTABLE)
            ;
        }

        if ($end !== null) {
            $qb
                ->andWhere($qb->expr()->lte('t.begin', ':end'))
                ->setParameter('end', $end, Types::DATETIME_MUTABLE)
            ;
        }

        return $qb;
    }
}
