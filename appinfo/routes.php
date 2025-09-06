<?php

return [
	'routes' => [
		// Configuration routes
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		
		// API routes
		['name' => 'itopAPI#getTickets', 'url' => '/tickets', 'verb' => 'GET'],
		['name' => 'itopAPI#getTicket', 'url' => '/tickets/{ticketId}', 'verb' => 'GET'],
		['name' => 'itopAPI#getAvatar', 'url' => '/avatar', 'verb' => 'GET'],
		['name' => 'itopAPI#getCIs', 'url' => '/cis', 'verb' => 'GET'],
		
		// Search routes
		['name' => 'itopAPI#search', 'url' => '/search', 'verb' => 'GET'],
	]
];
