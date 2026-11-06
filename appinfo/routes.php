<?php
return ['routes' => [
	['name' => 'api#accounts', 'url' => '/api/accounts', 'verb' => 'GET'],
	['name' => 'api#discover', 'url' => '/api/discover', 'verb' => 'POST'],
	['name' => 'api#connect', 'url' => '/api/connect', 'verb' => 'POST'],
	['name' => 'api#accountDiscover', 'url' => '/api/accounts/{id}/discover', 'verb' => 'POST'],
	['name' => 'api#accountAdd', 'url' => '/api/accounts/{id}/calendars', 'verb' => 'POST'],
	['name' => 'api#accountPassword', 'url' => '/api/accounts/{id}/password', 'verb' => 'POST'],
	['name' => 'api#accountDelete', 'url' => '/api/accounts/{id}', 'verb' => 'DELETE'],
	['name' => 'api#bindingSync', 'url' => '/api/bindings/{id}/sync', 'verb' => 'POST'],
	['name' => 'api#bindingDelete', 'url' => '/api/bindings/{id}', 'verb' => 'DELETE'],
	['name' => 'api#adminSave', 'url' => '/api/admin', 'verb' => 'POST'],
]];
