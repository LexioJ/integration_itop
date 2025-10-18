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

		// API routes
		['name' => 'itopAPI#getTickets', 'url' => '/tickets', 'verb' => 'GET'],
		['name' => 'itopAPI#getTicketsCount', 'url' => '/tickets/count', 'verb' => 'GET'],
		['name' => 'itopAPI#getTicket', 'url' => '/tickets/{ticketId}', 'verb' => 'GET'],
		['name' => 'itopAPI#getAvatar', 'url' => '/avatar', 'verb' => 'GET'],
		['name' => 'itopAPI#getCIs', 'url' => '/cis', 'verb' => 'GET'],
		
		// Search routes
		['name' => 'itopAPI#search', 'url' => '/search', 'verb' => 'GET'],
	]
];
