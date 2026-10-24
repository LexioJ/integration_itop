<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Command;

use OCA\Itop\Service\Binding\BindingConfig;
use OCA\Itop\Service\Binding\IdentityBindingService;
use OCA\Itop\Service\Binding\IdentityResolver;
use OCA\Itop\Service\Binding\MatchResult;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Binds Nextcloud users to their iTop identity from the command line.
 *
 * Dry-run by default: --apply is required before anything is written.
 */
class BindUsers extends Command {

	public function __construct(
		private IUserManager $userManager,
		private IdentityResolver $resolver,
		private IdentityBindingService $bindingService,
		private BindingConfig $bindingConfig,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('itop:bind')
			->setDescription('Bind Nextcloud users to their iTop identity')
			->addOption('apply', null, InputOption::VALUE_NONE,
				'Write the bindings. Without this the command only reports what it would do.')
			->addOption('include-review', null, InputOption::VALUE_NONE,
				'Also bind matches whose name differs from the iTop person. Requires --apply.')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED,
				'Only process this Nextcloud user id')
			->addOption('limit', null, InputOption::VALUE_REQUIRED,
				'Stop after examining this many users');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$includeReview = (bool)$input->getOption('include-review');
		$limit = $input->getOption('limit') !== null ? (int)$input->getOption('limit') : null;

		if ($includeReview && !$apply) {
			$output->writeln('<error>--include-review only makes sense together with --apply</error>');
			return 1;
		}

		$output->writeln(sprintf(
			'Matching Nextcloud <info>%s</info> against iTop <info>%s</info>%s',
			$this->bindingConfig->getMatchSource(),
			$this->bindingConfig->getMatchTarget(),
			$apply ? '' : '  (dry run - nothing will be written)'
		));

		$only = $input->getOption('user');
		$users = $this->collectUsers($only);

		if ($only === null) {
			$output->writeln(sprintf('Examining %d Nextcloud account(s).', count($users)));
		}
		$output->writeln('');

		$counts = [MatchResult::TIER_EXACT => 0, MatchResult::TIER_REVIEW => 0,
			MatchResult::TIER_AMBIGUOUS => 0, MatchResult::TIER_NONE => 0];
		$bound = 0;
		$skipped = 0;
		$examined = 0;
		$failed = 0;
		$stoppedAtLimit = false;

		foreach ($users as $user) {
			if ($limit !== null && $examined >= $limit) {
				$stoppedAtLimit = true;
				break;
			}
			$examined++;

			$uid = $user->getUID();

			if ($this->bindingConfig->isExcluded($uid)) {
				$skipped++;
				continue;
			}
			if ($this->bindingService->isBound($uid)) {
				$skipped++;
				continue;
			}

			$result = $this->resolver->resolve($user);
			$counts[$result->tier]++;

			$shouldBind = $result->isBindable()
				|| ($includeReview && $result->tier === MatchResult::TIER_REVIEW);

			$output->writeln($this->describe($uid, $result));

			if ($apply && $shouldBind && $result->personId !== null) {
				try {
					$this->bindingService->bind(
						$uid, $result->personId, $result->userId,
						IdentityBindingService::METHOD_ADMIN,
						$this->bindingConfig->getMatchSource()
					);
					$bound++;
				} catch (\Throwable $e) {
					$failed++;
					$output->writeln(sprintf('  <error>failed</error>     %-24s %s', $uid, $e->getMessage()));
				}
			}
		}

		$output->writeln('');

		if ($stoppedAtLimit) {
			$remaining = count($users) - $examined;
			$output->writeln(sprintf(
				'<comment>stopped after --limit %d; %d account(s) were not examined</comment>',
				$limit, $remaining
			));
		}

		$output->writeln(sprintf(
			'examined %d · exact %d · review %d · ambiguous %d · no match %d · skipped %d · failed %d',
			$examined, $counts[MatchResult::TIER_EXACT], $counts[MatchResult::TIER_REVIEW],
			$counts[MatchResult::TIER_AMBIGUOUS], $counts[MatchResult::TIER_NONE], $skipped, $failed
		));

		if ($apply) {
			$output->writeln(sprintf('<info>bound %d user(s)</info>', $bound));
		} else {
			$output->writeln('<comment>dry run - re-run with --apply to write these bindings</comment>');
		}

		return $failed > 0 ? 1 : 0;
	}

	/**
	 * @return IUser[]
	 */
	private function collectUsers(?string $only): array {
		if ($only !== null) {
			$user = $this->userManager->get($only);
			return $user !== null ? [$user] : [];
		}

		$users = [];
		$this->userManager->callForAllUsers(static function (IUser $user) use (&$users) {
			$users[] = $user;
		});

		return $users;
	}

	private function describe(string $uid, MatchResult $result): string {
		return match ($result->tier) {
			MatchResult::TIER_EXACT => sprintf('  <info>exact</info>      %-24s -> %s (person %s)',
				$uid, $result->itopName, $result->personId),
			MatchResult::TIER_REVIEW => sprintf('  <comment>review</comment>     %-24s -> %s (person %s)  names differ: "%s" vs "%s"',
				$uid, $result->itopName, $result->personId, $result->ncName, $result->itopName),
			MatchResult::TIER_AMBIGUOUS => sprintf('  <error>ambiguous</error>  %-24s -> %d candidates for "%s"',
				$uid, count($result->candidates), $result->sourceValue),
			default => sprintf('  no match   %-24s (%s)',
				$uid, $result->sourceValue === '' ? 'no source value' : $result->sourceValue),
		};
	}
}
