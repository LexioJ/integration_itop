<?php

/**
 * Nextcloud - iTop
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author iTop Integration Team
 * @copyright iTop Integration Team 2025
 */

namespace OCA\Itop\Service;

use OCA\Itop\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Preview Mapper Service
 *
 * Transforms iTop CI data into preview DTOs for consistent rendering
 * across Unified Search, Smart Picker, and Rich Preview widgets
 *
 * Handles class-specific field mappings defined in docs/class-mapping.md
 */
class PreviewMapper {

	public function __construct(
		private IConfig $config,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Map CI data from iTop API to preview DTO
	 *
	 * @param array $ciData CI data from iTop API (fields from core/get response)
	 * @param string $class iTop class name (PC, Phone, WebApplication, etc.)
	 * @return array Preview DTO with title, subtitle, badges, chips, extras, etc.
	 */
	public function mapCIToPreview(array $ciData, string $class): array {
		$fields = $ciData['fields'] ?? $ciData;

		// Build preview DTO structure
		$preview = [
			'id' => $fields['id'] ?? null,
			'class' => $class,
			'title' => $this->getTitle($fields),
			'subtitle' => $this->getSubtitle($fields, $class),
			'badges' => $this->formatBadges($fields),
			'chips' => $this->formatChips($fields),
			'extras' => $this->getClassSpecificExtras($fields, $class),
			'description' => $this->formatDescription($fields),
			'timestamps' => $this->formatTimestamps($fields),
			'url' => $this->buildItopUrl($fields, $class),
			'icon' => $this->getClassIcon($class),
		];

		return $preview;
	}

	/**
	 * Get title for preview (uses name field)
	 *
	 * @param array $fields CI fields
	 * @return string Title text
	 */
	private function getTitle(array $fields): string {
		return $fields['name'] ?? $fields['friendlyname'] ?? 'Unknown CI';
	}

	/**
	 * Get subtitle for preview (format: "Class • Organization")
	 *
	 * @param array $fields CI fields
	 * @param string $class iTop class name
	 * @return string Subtitle text
	 */
	private function getSubtitle(array $fields, string $class): string {
		$parts = [];

		// Add class label
		$parts[] = $this->getClassLabel($class);

		// Add organization
		if (!empty($fields['org_id_friendlyname'])) {
			$parts[] = $fields['org_id_friendlyname'];
		}

		return implode(' • ', $parts);
	}

	/**
	 * Format status and criticality badges
	 *
	 * @param array $fields CI fields
	 * @return array List of badge objects
	 */
	private function formatBadges(array $fields): array {
		$badges = [];

		// Status badge
		if (!empty($fields['status'])) {
			$badges[] = [
				'label' => ucfirst($fields['status']),
				'type' => $this->getStatusBadgeType($fields['status'])
			];
		}

		// Business criticality badge
		if (!empty($fields['business_criticity'])) {
			$badges[] = [
				'label' => $fields['business_criticity'],
				'type' => $this->getCriticalityBadgeType($fields['business_criticity'])
			];
		}

		return $badges;
	}

	/**
	 * Format info chips (location, asset numbers, brand/model)
	 *
	 * @param array $fields CI fields
	 * @return array List of chip objects
	 */
	private function formatChips(array $fields): array {
		$chips = [];

		// Location
		if (!empty($fields['location_id_friendlyname'])) {
			$chips[] = [
				'icon' => 'map-marker',
				'label' => $fields['location_id_friendlyname']
			];
		}

		// Asset number
		if (!empty($fields['asset_number'])) {
			$chips[] = [
				'icon' => 'barcode',
				'label' => $fields['asset_number']
			];
		}

		// Serial number
		if (!empty($fields['serialnumber'])) {
			$chips[] = [
				'icon' => 'identifier',
				'label' => 'SN: ' . $fields['serialnumber']
			];
		}

		// Brand/Model (combined)
		$brandModel = $this->formatBrandModel($fields);
		if ($brandModel) {
			$chips[] = [
				'icon' => 'tag',
				'label' => $brandModel
			];
		}

		return $chips;
	}

	/**
	 * Get class-specific extra fields
	 *
	 * @param array $fields CI fields
	 * @param string $class iTop class name
	 * @return array List of extra field objects
	 */
	private function getClassSpecificExtras(array $fields, string $class): array {
		$extras = [];

		switch ($class) {
			case 'PC':
				if (!empty($fields['type'])) {
					$extras[] = ['label' => 'Type', 'value' => $fields['type']];
				}
				if (!empty($fields['osfamily_id_friendlyname'])) {
					$extras[] = ['label' => 'OS', 'value' => $fields['osfamily_id_friendlyname']];
				}
				if (!empty($fields['osversion_id_friendlyname'])) {
					$extras[] = ['label' => 'OS Version', 'value' => $fields['osversion_id_friendlyname']];
				}
				if (!empty($fields['cpu'])) {
					$extras[] = ['label' => 'CPU', 'value' => $fields['cpu']];
				}
				if (!empty($fields['ram'])) {
					$extras[] = ['label' => 'RAM', 'value' => $fields['ram']];
				}
				break;

			case 'Phone':
			case 'IPPhone':
				if (!empty($fields['phonenumber'])) {
					$extras[] = ['label' => 'Phone', 'value' => $fields['phonenumber']];
				}
				break;

			case 'MobilePhone':
				if (!empty($fields['phonenumber'])) {
					$extras[] = ['label' => 'Phone', 'value' => $fields['phonenumber']];
				}
				if (!empty($fields['imei'])) {
					$extras[] = ['label' => 'IMEI', 'value' => $fields['imei']];
				}
				break;

			case 'WebApplication':
				if (!empty($fields['url'])) {
					$extras[] = ['label' => 'URL', 'value' => $fields['url']];
				}
				if (!empty($fields['webserver_name'])) {
					$extras[] = ['label' => 'Web Server', 'value' => $fields['webserver_name']];
				}
				break;

			case 'PCSoftware':
			case 'OtherSoftware':
				if (!empty($fields['system_name'])) {
					$extras[] = ['label' => 'System', 'value' => $fields['system_name']];
				}
				if (!empty($fields['software_id_friendlyname'])) {
					$extras[] = ['label' => 'Software', 'value' => $fields['software_id_friendlyname']];
				}
				if (!empty($fields['softwarelicence_id_friendlyname'])) {
					$extras[] = ['label' => 'License', 'value' => $fields['softwarelicence_id_friendlyname']];
				}
				if (!empty($fields['path'])) {
					$extras[] = ['label' => 'Path', 'value' => $fields['path']];
				}
				break;

			// Tablet, Printer, Peripheral have no class-specific extras beyond common fields
		}

		return $extras;
	}

	/**
	 * Format description with HTML stripping and length limit
	 *
	 * @param array $fields CI fields
	 * @return string|null Formatted description or null
	 */
	private function formatDescription(array $fields): ?string {
		$description = $fields['description'] ?? '';

		if (empty($description)) {
			return null;
		}

		// Strip HTML tags
		$description = strip_tags($description);

		// Limit length to 300 characters
		if (strlen($description) > 300) {
			$description = substr($description, 0, 297) . '...';
		}

		return $description;
	}

	/**
	 * Format timestamps (last update, move to production)
	 *
	 * @param array $fields CI fields
	 * @return array Timestamp objects
	 */
	private function formatTimestamps(array $fields): array {
		$timestamps = [];

		if (!empty($fields['last_update'])) {
			$timestamps['last_update'] = [
				'label' => 'Last Updated',
				'value' => $fields['last_update'],
				'formatted' => $this->formatDate($fields['last_update'])
			];
		}

		if (!empty($fields['move2production'])) {
			$timestamps['move2production'] = [
				'label' => 'In Production Since',
				'value' => $fields['move2production'],
				'formatted' => $this->formatDate($fields['move2production'])
			];
		}

		return $timestamps;
	}

	/**
	 * Format date string for display
	 *
	 * @param string $dateString Date string from iTop (e.g., "2025-01-15 10:30:00")
	 * @return string Formatted date
	 */
	private function formatDate(string $dateString): string {
		try {
			$date = new \DateTime($dateString);
			return $date->format('Y-m-d H:i');
		} catch (\Exception $e) {
			return $dateString;
		}
	}

	/**
	 * Build iTop URL for CI details page
	 *
	 * @param array $fields CI fields
	 * @param string $class iTop class name
	 * @return string URL to CI in iTop
	 */
	private function buildItopUrl(array $fields, string $class): string {
		$itopUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url', '');
		$ciId = $fields['id'] ?? '';

		if (empty($itopUrl) || empty($ciId)) {
			return '';
		}

		return rtrim($itopUrl, '/') . '/pages/UI.php?operation=details&class=' . urlencode($class) . '&id=' . urlencode($ciId);
	}

	/**
	 * Get icon filename for CI class
	 *
	 * @param string $class iTop class name
	 * @return string Icon filename (without path)
	 */
	private function getClassIcon(string $class): string {
		// Map classes to icon files (icon files should be in img/ directory)
		$iconMap = [
			'PC' => 'PC.svg',
			'Phone' => 'Phone.svg',
			'IPPhone' => 'Phone.svg',
			'MobilePhone' => 'Phone.svg',
			'Tablet' => 'Tablet.svg',
			'Printer' => 'Printer.svg',
			'Peripheral' => 'Peripheral.svg',
			'PCSoftware' => 'PCSoftware.svg',
			'OtherSoftware' => 'Software.svg',
			'WebApplication' => 'WebApplication.svg',
		];

		return $iconMap[$class] ?? 'FunctionalCI.svg'; // Fallback icon
	}

	/**
	 * Get human-readable label for CI class
	 *
	 * @param string $class iTop class name
	 * @return string Human-readable label
	 */
	private function getClassLabel(string $class): string {
		$labels = [
			'PC' => $this->l10n->t('Computer'),
			'Phone' => $this->l10n->t('Phone'),
			'IPPhone' => $this->l10n->t('IP Phone'),
			'MobilePhone' => $this->l10n->t('Mobile Phone'),
			'Tablet' => $this->l10n->t('Tablet'),
			'Printer' => $this->l10n->t('Printer'),
			'Peripheral' => $this->l10n->t('Peripheral'),
			'PCSoftware' => $this->l10n->t('Software'),
			'OtherSoftware' => $this->l10n->t('Software'),
			'WebApplication' => $this->l10n->t('Web Application'),
		];

		return $labels[$class] ?? $class;
	}

	/**
	 * Format brand and model as single string
	 *
	 * @param array $fields CI fields
	 * @return string|null Formatted brand/model or null
	 */
	private function formatBrandModel(array $fields): ?string {
		$brand = $fields['brand_id_friendlyname'] ?? '';
		$model = $fields['model_id_friendlyname'] ?? '';

		if (empty($brand) && empty($model)) {
			return null;
		}

		if (!empty($brand) && !empty($model)) {
			return $brand . ' ' . $model;
		}

		return $brand ?: $model;
	}

	/**
	 * Get badge type for status value
	 *
	 * @param string $status Status value (production, implementation, obsolete, etc.)
	 * @return string Badge type (success, warning, error, neutral)
	 */
	private function getStatusBadgeType(string $status): string {
		$statusMap = [
			'production' => 'success',
			'implementation' => 'info',
			'active' => 'success',
			'obsolete' => 'error',
			'stock' => 'neutral',
		];

		return $statusMap[strtolower($status)] ?? 'neutral';
	}

	/**
	 * Get badge type for business criticality
	 *
	 * @param string $criticality Criticality value (high, medium, low)
	 * @return string Badge type
	 */
	private function getCriticalityBadgeType(string $criticality): string {
		$criticalityMap = [
			'high' => 'error',
			'medium' => 'warning',
			'low' => 'info',
		];

		return $criticalityMap[strtolower($criticality)] ?? 'neutral';
	}
}
