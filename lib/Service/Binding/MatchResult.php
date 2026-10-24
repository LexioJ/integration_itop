<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

/**
 * Outcome of matching one Nextcloud user against iTop.
 *
 * Only TIER_EXACT is bindable without a human decision. TIER_REVIEW means the
 * attribute matched but the names disagree; TIER_AMBIGUOUS means several
 * people matched and the app refuses to guess.
 */
class MatchResult {

	public const TIER_EXACT = 'exact';
	public const TIER_REVIEW = 'review';
	public const TIER_AMBIGUOUS = 'ambiguous';
	public const TIER_NONE = 'none';

	/**
	 * @param array $candidates list of ['person_id' => string, 'user_id' => ?string, 'name' => string]
	 */
	private function __construct(
		public readonly string $tier,
		public readonly ?string $personId,
		public readonly ?string $userId,
		public readonly string $itopName,
		public readonly string $ncName,
		public readonly string $sourceValue,
		public readonly array $candidates,
	) {
	}

	public static function exact(string $personId, ?string $userId, string $itopName, string $sourceValue): self {
		return new self(self::TIER_EXACT, $personId, $userId, $itopName, '', $sourceValue, []);
	}

	public static function review(string $personId, ?string $userId, string $itopName, string $sourceValue, string $ncName): self {
		return new self(self::TIER_REVIEW, $personId, $userId, $itopName, $ncName, $sourceValue, []);
	}

	public static function ambiguous(array $candidates, string $sourceValue): self {
		return new self(self::TIER_AMBIGUOUS, null, null, '', '', $sourceValue, $candidates);
	}

	public static function none(string $sourceValue): self {
		return new self(self::TIER_NONE, null, null, '', '', $sourceValue, []);
	}

	/**
	 * Whether this result may be applied without a human confirming it.
	 */
	public function isBindable(): bool {
		return $this->tier === self::TIER_EXACT;
	}
}
