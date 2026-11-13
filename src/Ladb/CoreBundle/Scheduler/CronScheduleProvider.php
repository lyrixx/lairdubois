<?php

namespace Ladb\CoreBundle\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The crons of the site, in the time of Paris. The worker of the schedule ("messenger:consume
 * scheduler_cron") puts each run in the queue "cron" at its time (RedispatchMessage), and the worker
 * of the queue ("messenger:consume cron") runs its command (RunCommandMessageHandler of Console), one
 * cron at a time. The schedule keeps its state and its lock in Memcached ("scheduler" in config.yml):
 * only one worker of the schedule at a time, and a run missed while it was stopped comes when it
 * starts again, the last one of each cron only.
 */
#[AsSchedule('cron')]
final readonly class CronScheduleProvider implements ScheduleProviderInterface {

	private const TIMEZONE = 'Europe/Paris';

	private const TRANSPORT = 'cron';

	// The cron expression and the command line of each cron
	private const CRONS = array(
		array( '0 4 * * 5', 'ladb:mailing:weeknews --force' ),
		array( '0 * * * *', 'ladb:cron:spotlight --force' ),
		array( '*/2 * * * *', 'ladb:cron:notification:populate --force' ),
		array( '0 7,11,17,20 * * *', 'ladb:cron:notification:email --force' ),
		array( '0 3 * * *', 'ladb:cron:sitemaps --force' ),
		array( '*/5 * * * *', 'ladb:cron:workflow:thumbnails --force' ),
		array( '0 3 * * *', 'ladb:cron:offers --force' ),
		array( '*/5 * * * *', 'ladb:cron:opencutlist:access:analyze --force' ),
	);

	public function getSchedule(): Schedule {
		$schedule = new Schedule();
		foreach (self::CRONS as list( $expression, $commandLine )) {
			$schedule->add(RecurringMessage::cron($expression, new RedispatchMessage(new RunCommandMessage($commandLine), self::TRANSPORT), self::TIMEZONE));
		}
		return $schedule;
	}

}
