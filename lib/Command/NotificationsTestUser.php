<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Command;

use OCA\Itop\AppInfo\Application;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NotificationsTestUser extends Command {

	public function __construct(
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('itop:notifications:test-user')
			->setDescription('Test or reset notification checks for a user')
			->addArgument(
				'user',
				InputArgument::REQUIRED,
				'Nextcloud user ID to test'
			)
			->addOption(
				'reset',
				'r',
				InputOption::VALUE_NONE,
				'Reset last check timestamps'
			)
			->addOption(
				'portal',
				'p',
				InputOption::VALUE_NONE,
				'Test portal notifications (default)'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		$reset = $input->getOption('reset');

		if ($reset) {
			return $this->resetTimestamps($userId, $output);
		}

		return $this->testNotifications($userId, $output);
	}

	private function resetTimestamps(string $userId, OutputInterface $output): int {
		$output->writeln("<info>Resetting notification timestamps for user: $userId</info>");

		// Reset portal check timestamp
		$this->config->deleteUserValue($userId, Application::APP_ID, 'last_portal_check');
		$output->writeln('  ✓ Reset last_portal_check');

		// Future: Reset agent check timestamp when Phase 2 is implemented
		// $this->config->deleteUserValue($userId, Application::APP_ID, 'last_agent_check');

		$output->writeln('<info>Done! Next background job run will check all changes since 30 days ago.</info>');

		return 0;
	}

	private function testNotifications(string $userId, OutputInterface $output): int {
		$output->writeln("<info>Testing portal notifications for user: $userId</info>");
		$output->writeln('');

		// Check configuration
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		$notificationEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		$lastCheck = $this->config->getUserValue($userId, Application::APP_ID, 'last_portal_check', '');

		if (empty($personId)) {
			$output->writeln('<error>Error: User has no person_id configured</error>');
			$output->writeln('User must configure their personal token first.');
			return 1;
		}

		$output->writeln("Person ID: <comment>$personId</comment>");
		$output->writeln('Notifications enabled: ' . ($notificationEnabled ? '<info>Yes</info>' : '<error>No</error>'));

		if (!$notificationEnabled) {
			$output->writeln('');
			$output->writeln('<comment>Note: Notifications are disabled for this user.</comment>');
			$output->writeln('Enable in Personal Settings → iTop Integration → Notification Settings');
			return 1;
		}

		$output->writeln('Last check: ' . ($lastCheck ?: '<comment>Never</comment>'));

		// Check preferences
		$output->writeln('');
		$output->writeln('<info>Notification preferences:</info>');
		$notifyStatusChanged = $this->config->getUserValue($userId, Application::APP_ID, 'notify_ticket_status_changed', '1') === '1';
		$notifyAgentResponded = $this->config->getUserValue($userId, Application::APP_ID, 'notify_agent_responded', '1') === '1';
		$notifyTicketResolved = $this->config->getUserValue($userId, Application::APP_ID, 'notify_ticket_resolved', '1') === '1';

		$output->writeln('  Ticket status changed: ' . ($notifyStatusChanged ? '✓' : '✗'));
		$output->writeln('  Agent responded: ' . ($notifyAgentResponded ? '✓' : '✗'));
		$output->writeln('  Ticket resolved: ' . ($notifyTicketResolved ? '✓' : '✗'));

		// Get admin interval
		$interval = (int)$this->config->getAppValue(Application::APP_ID, 'portal_notification_interval', '15');
		$output->writeln('');
		$output->writeln("Admin configured interval: <comment>{$interval} minutes</comment>");

		// Check if user would be processed
		if (!empty($lastCheck)) {
			$lastCheckTime = strtotime($lastCheck);
			$now = time();
			$elapsedMinutes = round(($now - $lastCheckTime) / 60);
			$output->writeln("Time since last check: <comment>{$elapsedMinutes} minutes</comment>");

			if ($elapsedMinutes >= $interval) {
				$output->writeln('<info>✓ User would be processed on next job run</info>');
			} else {
				$remaining = $interval - $elapsedMinutes;
				$output->writeln("<comment>⏳ User will be processed in ~{$remaining} minutes</comment>");
			}
		} else {
			$output->writeln('<info>✓ User would be processed on next job run (first time)</info>');
		}

		$output->writeln('');
		$output->writeln('<info>To manually trigger a check:</info>');
		$output->writeln('  <comment>1. Find the job ID:</comment>');
		$output->writeln("     <comment>occ background-job:list | grep CheckPortalTicketUpdates</comment>");
		$output->writeln('  <comment>2. Execute the job:</comment>');
		$output->writeln('     <comment>occ background-job:execute <job-id> --force-execute</comment>');
		$output->writeln('');
		$output->writeln('<info>To reset timestamps and force a check:</info>');
		$output->writeln("  <comment>occ itop:notifications:test-user $userId --reset</comment>");

		return 0;
	}
}
