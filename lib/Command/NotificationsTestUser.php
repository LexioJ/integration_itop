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

		// Reset portal check timestamp (Unix timestamp format)
		$this->config->deleteUserValue($userId, Application::APP_ID, 'notification_last_portal_check');
		$output->writeln('  ✓ Reset notification_last_portal_check');
		
		// Clean up old timestamp format if it exists
		$this->config->deleteUserValue($userId, Application::APP_ID, 'last_portal_check');

		// Future: Reset agent check timestamp when Phase 2 is implemented
		// $this->config->deleteUserValue($userId, Application::APP_ID, 'notification_last_agent_check');

		$output->writeln('<info>Done! Next background job run will check all changes since 30 days ago.</info>');

		return 0;
	}

	private function testNotifications(string $userId, OutputInterface $output): int {
		$output->writeln("<info>Testing portal notifications for user: $userId</info>");
		$output->writeln('');

		// Check configuration
		$personId = $this->config->getUserValue($userId, Application::APP_ID, 'person_id', '');
		$notificationEnabled = $this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1';
		$lastCheckStr = $this->config->getUserValue($userId, Application::APP_ID, 'notification_last_portal_check', '');

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

		// Display last check timestamp
		if (!empty($lastCheckStr)) {
			$lastCheckTime = (int)$lastCheckStr;
			$lastCheckDate = date('Y-m-d H:i:s', $lastCheckTime);
			$output->writeln("Last check: <comment>$lastCheckDate (Unix: $lastCheckTime)</comment>");
		} else {
			$output->writeln('Last check: <comment>Never</comment>');
		}

		// Check preferences (3-state notification system)
		$output->writeln('');
		$output->writeln('<info>Notification preferences (3-state system):</info>');
		
		$disabledPortalStr = $this->config->getUserValue($userId, Application::APP_ID, 'disabled_portal_notifications', '');
		if ($disabledPortalStr === 'all') {
			$output->writeln('  <error>All portal notifications disabled</error>');
		} else {
			$disabledPortal = !empty($disabledPortalStr) ? json_decode($disabledPortalStr, true) : [];
			if (!is_array($disabledPortal)) {
				$disabledPortal = [];
			}
			
			$output->writeln('  Ticket status changed: ' . (in_array('ticket_status_changed', $disabledPortal) ? '✗' : '✓'));
			$output->writeln('  Agent responded: ' . (in_array('agent_responded', $disabledPortal) ? '✗' : '✓'));
			$output->writeln('  Ticket resolved: ' . (in_array('ticket_resolved', $disabledPortal) ? '✗' : '✓'));
			$output->writeln('  Agent assigned: ' . (in_array('agent_assigned', $disabledPortal) ? '✗' : '✓'));
		}

		// Get admin default interval and user's interval
		$adminInterval = (int)$this->config->getAppValue(Application::APP_ID, 'default_notification_interval', '60');
		$userInterval = (int)$this->config->getUserValue($userId, Application::APP_ID, 'notification_check_interval', (string)$adminInterval);
		$output->writeln('');
		$output->writeln("Admin default interval: <comment>{$adminInterval} minutes</comment>");
		$output->writeln("User interval: <comment>{$userInterval} minutes</comment>");

		// Check if user would be processed
		if (!empty($lastCheckStr)) {
			$lastCheckTime = (int)$lastCheckStr;
			$now = time();
			$elapsedMinutes = round(($now - $lastCheckTime) / 60);
			$output->writeln("Time since last check: <comment>{$elapsedMinutes} minutes</comment>");

			if ($elapsedMinutes >= $userInterval) {
				$output->writeln('<info>✓ User would be processed on next job run</info>');
			} else {
				$remaining = $userInterval - $elapsedMinutes;
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
