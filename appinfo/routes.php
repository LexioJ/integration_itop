<?php

return [
	'routes' => [
		// Configuration routes
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#getUserInfo', 'url' => '/user-info', 'verb' => 'GET'],
		['name' => 'config#getAdminConfig', 'url' => '/admin-config', 'verb' => 'GET'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#testAdminConnection', 'url' => '/admin-config/test', 'verb' => 'POST'],
		['name' => 'config#testApplicationToken', 'url' => '/admin-config/test-token', 'verb' => 'POST'],
		['name' => 'config#saveNotificationSettings', 'url' => '/notification-settings', 'verb' => 'POST'],
		['name' => 'config#saveNotificationConfig', 'url' => '/notification-config', 'verb' => 'POST'],
		['name' => 'config#saveCacheSettings', 'url' => '/cache-settings', 'verb' => 'POST'],
		['name' => 'config#clearAllCache', 'url' => '/clear-cache', 'verb' => 'POST'],
		['name' => 'config#saveCIClassConfig', 'url' => '/ci-class-config', 'verb' => 'POST'],
		['name' => 'config#saveEnabledCIClasses', 'url' => '/enabled-ci-classes', 'verb' => 'POST'],
		['name' => 'config#getUserDisabledCIClasses', 'url' => '/user-disabled-ci-classes', 'verb' => 'GET'],
		['name' => 'config#saveUserDisabledCIClasses', 'url' => '/user-disabled-ci-classes', 'verb' => 'POST'],
		['name' => 'config#checkVersion', 'url' => '/version-check', 'verb' => 'GET'],

		// API routes
		['name' => 'itopAPI#getTickets', 'url' => '/tickets', 'verb' => 'GET'],
		['name' => 'itopAPI#getTicketsCount', 'url' => '/tickets/count', 'verb' => 'GET'],
		['name' => 'itopAPI#getTicket', 'url' => '/tickets/{ticketId}', 'verb' => 'GET'],
		['name' => 'itopAPI#getAvatar', 'url' => '/avatar', 'verb' => 'GET'],
		['name' => 'itopAPI#getCIs', 'url' => '/cis', 'verb' => 'GET'],
		['name' => 'itopAPI#getDashboardData', 'url' => '/dashboard', 'verb' => 'GET'],
		['name' => 'itopAPI#getAgentDashboardData', 'url' => '/agent-dashboard', 'verb' => 'GET'],

		// Search routes
		['name' => 'itopAPI#search', 'url' => '/search', 'verb' => 'GET'],
	]
];
