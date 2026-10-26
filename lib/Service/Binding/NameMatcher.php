<?php

/**
 * Nextcloud - iTop
 *
 * @author Integration Bot
 * @copyright Integration Bot 2025
 */

namespace OCA\Itop\Service\Binding;

/**
 * Compares a Nextcloud display name with an iTop person name.
 *
 * Comparison is strict: anything that does not normalise to exactly the same
 * token set is treated as a mismatch, which sends the match to the "review"
 * tier for a human to judge. "Alex Askin" and "Alexander Askin" therefore do
 * not match. That costs an administrator a click and avoids silently binding
 * a user to the wrong person.
 *
 * Pure functions: no API or cache access, so they are directly unit-testable.
 */
class NameMatcher {

	/**
	 * Reduce a name to a canonical form: lowercase, unaccented, punctuation
	 * treated as a separator, tokens sorted so word order does not matter
	 * (iTop reports first and last name separately, Nextcloud as one string).
	 */
	public static function normalize(string $name): string {
		$name = self::stripDiacritics($name);
		$name = mb_strtolower($name);

		// Any non-alphanumeric run is a separator, so "Schmidt-Berger" and
		// "Schmidt Berger" collapse to the same tokens.
		$tokens = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		sort($tokens, SORT_STRING);

		return implode(' ', $tokens);
	}

	public static function matches(string $a, string $b): bool {
		$normalizedA = self::normalize($a);
		if ($normalizedA === '') {
			return false;
		}
		return $normalizedA === self::normalize($b);
	}

	/**
	 * Decompose and drop combining marks, so "José" becomes "Jose".
	 *
	 * intl ships with every supported Nextcloud install, but guard anyway: a
	 * missing extension should degrade to plain comparison rather than fatal.
	 */
	private static function stripDiacritics(string $value): string {
		if (!class_exists(\Normalizer::class)) {
			return $value;
		}

		$decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
		if ($decomposed === false) {
			return $value;
		}

		return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $value;
	}
}
