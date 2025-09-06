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

namespace OCA\Itop\Search;

use OCP\Search\SearchResultEntry;

class ItopSearchResultEntry extends SearchResultEntry {

	/**
	 * @inheritDoc
	 */
	public function __construct(
		string $thumbnailUrl,
		string $title,
		string $subline,
		string $resourceUrl,
		string $icon = '',
		bool $rounded = false
	) {
		parent::__construct($thumbnailUrl, $title, $subline, $resourceUrl, $icon, $rounded);
	}
}
