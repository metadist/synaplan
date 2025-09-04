<?php
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message; // alias class exists under different namespaces depending on version; we'll type-hint loosely
use Webklex\PHPIMAP\Folder;
use Carbon\Carbon;

// Mail handler tools and prompt builder
class mailHandler {
	// ----------------------------------------------------------------------------------
	// OAuth helpers and storage (per user in BCONFIG)
	// ----------------------------------------------------------------------------------
	private static function getAuthMethodForUser(int $userId): string {
		$method = 'password';
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return $method; }
		$sql = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler' AND BSETTING='authMethod' LIMIT 1";
		$res = DB::Query($sql);
		$row = DB::FetchArr($res);
		if ($row && strlen($row['BVALUE']) > 0) { $method = $row['BVALUE']; }
		return $method;
	}

	public static function setAuthMethodForUser(int $userId, string $authMethod): void {
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return; }
		$authMethod = DB::EscString($authMethod);
		$check = "SELECT BID FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler' AND BSETTING='authMethod' LIMIT 1";
		$res = DB::Query($check);
		if (DB::CountRows($res) > 0) {
			DB::Query("UPDATE BCONFIG SET BVALUE='".$authMethod."' WHERE BOWNERID=".$userId." AND BGROUP='mailhandler' AND BSETTING='authMethod'");
		} else {
			DB::Query("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (".$userId.", 'mailhandler', 'authMethod', '".$authMethod."')");
		}
	}

	private static function getOAuthRow(int $userId): array {
		$userId = max(0, (int)$userId);
		$rows = [];
		if ($userId <= 0) { return $rows; }
		$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_oauth'";
		$res = DB::Query($sql);
		while ($row = DB::FetchArr($res)) { $rows[$row['BSETTING']] = $row['BVALUE']; }
		return $rows;
	}

	private static function saveOAuthRow(int $userId, string $setting, string $value): void {
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return; }
		$setting = DB::EscString($setting);
		$value = DB::EscString($value);
		$check = "SELECT BID FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_oauth' AND BSETTING='".$setting."' LIMIT 1";
		$res = DB::Query($check);
		if (DB::CountRows($res) > 0) {
			DB::Query("UPDATE BCONFIG SET BVALUE='".$value."' WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_oauth' AND BSETTING='".$setting."'");
		} else {
			DB::Query("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (".$userId.", 'mailhandler_oauth', '".$setting."', '".$value."')");
		}
	}

	private static function clearOAuthRows(int $userId): void {
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return; }
		DB::Query("DELETE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_oauth'");
	}

	// ----------------------------------------------------------------------------------
	// Per-user OAuth App configuration (BYO app)
	// ----------------------------------------------------------------------------------
	private static function getUserOAuthApp(int $userId): array {
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return []; }
		$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_oauthapp'";
		$res = DB::Query($sql);
		$data = [];
		while ($row = DB::FetchArr($res)) { $data[$row['BSETTING']] = $row['BVALUE']; }
		return $data;
	}

	private static function createGoogleClientForUser(int $userId, string $redirectUri, array $scopes) {
		// Prefer per-user app; fall back to global OAuthConfig
		$app = self::getUserOAuthApp($userId);
		if (isset($app['provider']) && strtolower($app['provider']) === 'google' && !empty($app['google_client_id']) && !empty($app['google_client_secret'])) {
			if (!class_exists('Google_Client')) { throw new \Exception('Google client not installed'); }
			$client = new \Google_Client();
			$client->setClientId($app['google_client_id']);
			$client->setClientSecret($app['google_client_secret']);
			$client->setRedirectUri($redirectUri);
			foreach ($scopes as $scope) { $client->addScope($scope); }
			$client->setAccessType('offline');
			$client->setPrompt('consent');
			return $client;
		}
		require_once(__DIR__ . '/_oauth.php');
		return \OAuthConfig::createGoogleClient($redirectUri, $scopes);
	}

	/**
	 * Build provider auth URL for Gmail or Microsoft
	 * @return array{success:bool,authUrl?:string,error?:string}
	 */
	public static function oauthStart(string $provider, string $redirectUri, int $userId, string $email = ''): array {
		try {
			$provider = strtolower(trim($provider));
			$userId = max(0, (int)$userId);
			if ($userId <= 0) { return ['success' => false, 'error' => 'Invalid user']; }
			$_SESSION['mail_oauth_provider'] = $provider;
			$_SESSION['mail_oauth_user'] = $userId;
			$_SESSION['mail_oauth_redirect'] = $redirectUri;
			if ($email !== '') { self::saveOAuthRow($userId, 'email', $email); }
			if ($provider === 'google') {
				if (!class_exists('Google_Client')) { return ['success' => false, 'error' => 'Google client missing']; }
				$scopes = ['https://mail.google.com/'];
				$client = self::createGoogleClientForUser($userId, $redirectUri, $scopes);
				if ($email !== '') { $client->setLoginHint($email); }
				$authUrl = $client->createAuthUrl();
				return ['success' => true, 'authUrl' => $authUrl];
			} elseif ($provider === 'microsoft') {
				if (!class_exists('Stevenmaguire\OAuth2\Client\Provider\Microsoft')) { return ['success' => false, 'error' => 'Microsoft provider missing']; }
				$tenant = getenv('MS_OAUTH_TENANT') ?: 'common';
				$clientId = getenv('MS_OAUTH_CLIENT_ID') ?: '';
				$clientSecret = getenv('MS_OAUTH_CLIENT_SECRET') ?: '';
				$opts = [
					'clientId' => $clientId,
					'clientSecret' => $clientSecret,
					'redirectUri' => $redirectUri,
					'tenant' => $tenant
				];
				$ms = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft($opts);
				$scopes = ['offline_access', 'https://outlook.office.com/IMAP.AccessAsUser.All'];
				$authUrl = $ms->getAuthorizationUrl(['scope' => $scopes]);
				$_SESSION['oauth2state'] = $ms->getState();
				return ['success' => true, 'authUrl' => $authUrl];
			}
			return ['success' => false, 'error' => 'Unknown provider'];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Handle OAuth callback and persist token in BCONFIG
	 */
	public static function oauthCallback(string $provider, string $code, string $redirectUri, int $userId): array {
		try {
			$provider = strtolower(trim($provider));
			$userId = max(0, (int)$userId);
			if ($userId <= 0) { return ['success' => false, 'error' => 'Invalid user']; }
			if ($provider === 'google') {
				$client = self::createGoogleClientForUser($userId, $redirectUri, ['https://mail.google.com/']);
				$token = $client->fetchAccessTokenWithAuthCode($code);
				if (isset($token['error'])) { return ['success' => false, 'error' => $token['error_description'] ?? 'OAuth error']; }
				$email = self::getOAuthRow($userId)['email'] ?? '';
				self::saveOAuthRow($userId, 'provider', 'google');
				if ($email !== '') { self::saveOAuthRow($userId, 'email', $email); }
				self::saveOAuthRow($userId, 'access_token', json_encode($token));
				$refresh = $token['refresh_token'] ?? '';
				if ($refresh !== '') { self::saveOAuthRow($userId, 'refresh_token', $refresh); }
				$expiresAt = 0;
				if (isset($token['created']) && isset($token['expires_in'])) { $expiresAt = ((int)$token['created']) + ((int)$token['expires_in']); }
				if ($expiresAt > 0) { self::saveOAuthRow($userId, 'expires_at', (string)$expiresAt); }
				self::setAuthMethodForUser($userId, 'oauth_google');
				return ['success' => true];
			} elseif ($provider === 'microsoft') {
				$tenant = getenv('MS_OAUTH_TENANT') ?: 'common';
				$clientId = getenv('MS_OAUTH_CLIENT_ID') ?: '';
				$clientSecret = getenv('MS_OAUTH_CLIENT_SECRET') ?: '';
				$ms = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft([
					'clientId' => $clientId,
					'clientSecret' => $clientSecret,
					'redirectUri' => $redirectUri,
					'tenant' => $tenant
				]);
				$token = $ms->getAccessToken('authorization_code', ['code' => $code]);
				$email = self::getOAuthRow($userId)['email'] ?? '';
				self::saveOAuthRow($userId, 'provider', 'microsoft');
				if ($email !== '') { self::saveOAuthRow($userId, 'email', $email); }
				self::saveOAuthRow($userId, 'access_token', $token->getToken());
				if ($token->getRefreshToken()) { self::saveOAuthRow($userId, 'refresh_token', $token->getRefreshToken()); }
				if ($token->getExpires()) { self::saveOAuthRow($userId, 'expires_at', (string)$token->getExpires()); }
				self::setAuthMethodForUser($userId, 'oauth_microsoft');
				return ['success' => true];
			}
			return ['success' => false, 'error' => 'Unknown provider'];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	public static function oauthStatus(int $userId): array {
		$userId = max(0, (int)$userId);
		$rows = self::getOAuthRow($userId);
		$provider = $rows['provider'] ?? '';
		$expiresAt = isset($rows['expires_at']) ? (int)$rows['expires_at'] : 0;
		$connected = ($provider !== '');
		$now = time();
		$expiresIn = $expiresAt > 0 ? max(0, $expiresAt - $now) : 0;
		return [
			'success' => true,
			'connected' => $connected,
			'provider' => $provider,
			'expiresAt' => $expiresAt,
			'expiresIn' => $expiresIn,
			'email' => $rows['email'] ?? ''
		];
	}

	public static function oauthDisconnect(int $userId): array {
		self::clearOAuthRows($userId);
		self::setAuthMethodForUser($userId, 'password');
		return ['success' => true];
	}
	// ----------------------------------------------------------------------------------
	// Find all users who configured the mail handler (basic active criteria)
	// ----------------------------------------------------------------------------------
	public static function getUsersWithMailhandler(): array {
		$users = [];
		$sql = "
			SELECT b1.BOWNERID
			FROM BCONFIG b1
			WHERE b1.BGROUP = 'mailhandler'
			  AND b1.BSETTING IN ('server','username')
			  AND b1.BVALUE <> ''
			GROUP BY b1.BOWNERID
			HAVING COUNT(DISTINCT b1.BSETTING) >= 2
			   AND EXISTS (
				   SELECT 1 FROM BCONFIG b2
				   WHERE b2.BOWNERID = b1.BOWNERID
				     AND b2.BGROUP = 'mailhandler_dept'
			   )
		";
		$res = DB::Query($sql);
		while ($row = DB::FetchArr($res)) {
			$users[] = intval($row['BOWNERID']);
		}
		return $users;
	}

	// ----------------------------------------------------------------------------------
	// Build the target list JSON for a specific user
	// ----------------------------------------------------------------------------------
	public static function getTargetlistForUser(int $userId): string {
		$list = [];
		if ($userId > 0) {
			$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = " . $userId . " AND BGROUP = 'mailhandler_dept' ORDER BY CAST(BSETTING AS UNSIGNED) ASC";
			$res = DB::Query($sql);
			while ($row = DB::FetchArr($res)) {
				$parts = explode('|', $row['BVALUE']);
				$email = trim($parts[0] ?? '');
				$description = trim($parts[1] ?? '');
				$isDefault = (($parts[2] ?? '0') === '1');
				if ($email !== '') {
					$list[] = [
						'email' => $email,
						'description' => $description,
						'default' => $isDefault
					];
				}
			}
		}
		return json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	// ----------------------------------------------------------------------------------
	// Build the target list JSON from the user's mail handler departments in BCONFIG
	// ----------------------------------------------------------------------------------
	public static function getTargetlist(): string {
		$userId = 0;
		if (isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true && isset($_SESSION["widget_owner_id"])) {
			$userId = intval($_SESSION["widget_owner_id"]);
		} elseif (isset($_SESSION["USERPROFILE"]["BID"])) {
			$userId = intval($_SESSION["USERPROFILE"]["BID"]);
		}

		$list = [];
		if ($userId > 0) {
			$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = " . $userId . " AND BGROUP = 'mailhandler_dept' ORDER BY CAST(BSETTING AS UNSIGNED) ASC";
			$res = DB::Query($sql);
			while ($row = DB::FetchArr($res)) {
				$parts = explode('|', $row['BVALUE']);
				$email = trim($parts[0] ?? '');
				$description = trim($parts[1] ?? '');
				$isDefault = (($parts[2] ?? '0') === '1');
				if ($email !== '') {
					$list[] = [
						'email' => $email,
						'description' => $description,
						'default' => $isDefault
					];
				}
			}
		}

		return json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	// ----------------------------------------------------------------------------------
	// Build the full mail handler prompt by fetching the template and injecting targets
	// ----------------------------------------------------------------------------------
	public static function getMailprompt(): string {
		$promptKey = 'tools:mailhandler';
		$promptArr = BasicAI::getPromptDetails($promptKey);
		$prompt = '';

		if (isset($promptArr['BPROMPT']) && strlen($promptArr['BPROMPT']) > 0) {
			$prompt = $promptArr['BPROMPT'];
		}

		$targetJson = self::getTargetlist();
		
		if ($prompt !== '' && strpos($prompt, '[TARGETLIST]') !== false) {
			$prompt = str_replace('[TARGETLIST]', $targetJson, $prompt);
		}

		return $prompt;
	}

	// ----------------------------------------------------------------------------------
	// Build the mail handler prompt for a specific user (respects per-user prompts)
	// ----------------------------------------------------------------------------------
	public static function getMailpromptForUser(int $userId): string {
		// Backup session
		$backupUser = $_SESSION['USERPROFILE']['BID'] ?? null;
		if (!isset($_SESSION['USERPROFILE'])) { $_SESSION['USERPROFILE'] = []; }
		$_SESSION['USERPROFILE']['BID'] = $userId;

		$promptKey = 'tools:mailhandler';
		$promptArr = BasicAI::getPromptDetails($promptKey);
		$prompt = isset($promptArr['BPROMPT']) ? $promptArr['BPROMPT'] : '';

		$targetJson = self::getTargetlistForUser($userId);
		if ($prompt !== '' && strpos($prompt, '[TARGETLIST]') !== false) {
			$prompt = str_replace('[TARGETLIST]', $targetJson, $prompt);
		}

		// Restore session
		if ($backupUser === null) {
			unset($_SESSION['USERPROFILE']['BID']);
		} else {
			$_SESSION['USERPROFILE']['BID'] = $backupUser;
		}

		return $prompt;
	}

	// ----------------------------------------------------------------------------------
	// Run routing for a specific user against the standard sorting AI
	// ----------------------------------------------------------------------------------
	public static function runRoutingForUser(int $userId, string $subject, string $body): string {
		$systemPrompt = self::getMailpromptForUser($userId);
		$userPrompt = "Subject: " . trim($subject) . "\n\nBody: " . trim($body);

		// Use chat model/service for routing decisions
		$aiService = $GLOBALS["AI_CHAT"]["SERVICE"] ?? 'AIGoogle';
		if (!class_exists($aiService)) {
			return "Error: AI service not available: " . $aiService;
		}
		$response = $aiService::simplePrompt($systemPrompt, $userPrompt);
		if (is_array($response)) {
			if (isset($response['summary'])) { return trim($response['summary']); }
			if (isset($response['success']) && !$response['success']) { return "Error: AI call failed"; }
			return trim(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}
		return trim((string)$response);
	}

	// ==================================================================================================
	// IMAP FUNCTIONS (webklex/php-imap)
	// ==================================================================================================

	/**
	 * Retrieve IMAP configuration for a user from BCONFIG.
	 *
	 * Expected keys under `BGROUP = 'mailhandler'`:
	 * - server, port, protocol (imap|pop3), security (ssl|tls|none), username, password
	 *
	 * @param int $userId
	 * @return array{server:string,port:int,protocol:string,security:string,username:string,password:string}
	 */
	private static function getImapConfigForUser(int $userId): array {
		$cfg = [
			'server' => '',
			'port' => 993,
			'protocol' => 'imap',
			'security' => 'ssl',
			'username' => '',
			'password' => ''
		];
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return $cfg; }
		$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP = 'mailhandler'";
		$res = DB::Query($sql);
		while ($row = DB::FetchArr($res)) {
			switch ($row['BSETTING']) {
				case 'server': $cfg['server'] = $row['BVALUE']; break;
				case 'port': $cfg['port'] = (int)$row['BVALUE']; break;
				case 'protocol': $cfg['protocol'] = $row['BVALUE']; break;
				case 'security': $cfg['security'] = $row['BVALUE']; break;
				case 'username': $cfg['username'] = $row['BVALUE']; break;
				case 'password': $cfg['password'] = $row['BVALUE']; break;
			}
		}
		return $cfg;
	}

	/**
	 * Establish an IMAP/POP3 connection for a specific user.
	 * Uses webklex/php-imap ClientManager. Returns an array with success flag and client or error.
	 *
	 * @param int $userId
	 * @return array{success:bool, client: ?\Webklex\PHPIMAP\Client, error:?string}
	 */
	public static function imapConnectForUser(int $userId): array {
		try {
			Tools::debugCronLog("[IMAP] Begin connect for userId=".$userId."\n");
			$cfg = self::getImapConfigForUser($userId);
			$maskedUser = $cfg['username'];

			if ($cfg['server'] === '' || $cfg['username'] === '') {
				Tools::debugCronLog("[IMAP] Missing server or username\n");
				return ['success' => false, 'client' => null, 'error' => 'Missing server or username'];
			}
			$encryption = null;
			if ($cfg['security'] === 'ssl') { $encryption = 'ssl'; }
			elseif ($cfg['security'] === 'tls') { $encryption = 'tls'; }
			else { $encryption = false; }
			Tools::debugCronLog("[IMAP] Derived encryption=".($encryption === false ? 'none' : $encryption)."\n");

			$authMethod = self::getAuthMethodForUser($userId);
			$authentication = null;
			$password = $cfg['password'];
			if ($authMethod === 'oauth_google' || $authMethod === 'oauth_microsoft') {
				// Refresh token if needed and load access token
				$tokenInfo = self::refreshAccessTokenIfNeeded($userId, $authMethod);
				if (!$tokenInfo['success']) {
					return ['success' => false, 'client' => null, 'error' => 'OAuth token missing or refresh failed'];
				}
				$authentication = 'oauth';
				$password = $tokenInfo['access_token'];
			}

			Tools::debugCronLog("[IMAP] Creating ClientManager and client instance...\n");
			$cm = new ClientManager();
			$clientConfig = [
				'host'          => $cfg['server'],
				'port'          => $cfg['port'] ?: 993,
				'protocol'      => strtolower($cfg['protocol']) === 'pop3' ? 'pop3' : 'imap',
				'encryption'    => $encryption,
				'validate_cert' => true,
				'username'      => $cfg['username'],
				'password'      => $password,
				'authentication' => $authentication,
				'proxy'         => null,
			];
			// Remove null-only entries to avoid vendor merge issues and reduce noise
			$clientConfig = array_filter($clientConfig, function($v) { return $v !== null; });
			Tools::debugCronLog("[IMAP] Client config prepared (sanitized)\n".print_r($clientConfig, true));
			$client = $cm->make($clientConfig);
			Tools::debugCronLog("[IMAP] Connecting...\n");
			$client->connect();
			Tools::debugCronLog("[IMAP] Connected successfully\n");
			return ['success' => true, 'client' => $client, 'error' => null];
		} catch (\Throwable $e) {
			Tools::debugCronLog("[IMAP] Connection failed: ".$e->getMessage()."\n");
			return ['success' => false, 'client' => null, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Test an IMAP/POP3 connection using provided parameters (without persisting).
	 * Returns structured diagnostics for UI display.
	 *
	 * @param int $userId Current user id (used for OAuth tokens if selected)
	 * @param array $params
	 *  Expected keys: server, port, protocol (imap|pop3), security (ssl|tls|none), username, password, authMethod
	 * @return array
	 */
	public static function imapTestConnection(int $userId, array $params): array {
		try {
			$userId = max(0, (int)$userId);
			$server = trim((string)($params['server'] ?? ''));
			$port = (int)($params['port'] ?? 993);
			$protocol = strtolower(trim((string)($params['protocol'] ?? 'imap')));
			$security = strtolower(trim((string)($params['security'] ?? 'ssl')));
			$username = trim((string)($params['username'] ?? ''));
			$password = (string)($params['password'] ?? '');
			$authMethod = (string)($params['authMethod'] ?? '');
			if ($authMethod === '') { $authMethod = self::getAuthMethodForUser($userId); }
			if (!in_array($protocol, ['imap','pop3'], true)) { $protocol = 'imap'; }
			$encryption = null;
			if ($security === 'ssl') { $encryption = 'ssl'; }
			elseif ($security === 'tls') { $encryption = 'tls'; }
			else { $encryption = false; }

			if ($server === '' || $username === '') {
				return [
					'success' => false,
					'error' => 'Missing server or username',
					'connection' => [
						'host' => $server,
						'port' => $port,
						'protocol' => $protocol,
						'encryption' => $encryption === false ? 'none' : $encryption,
						'validate_cert' => true,
						'authMethod' => $authMethod !== '' ? $authMethod : 'password',
						'username' => $username
					]
				];
			}

			// Resolve OAuth access token if needed
			$authentication = null;
			if (in_array($authMethod, ['oauth_google','oauth_microsoft'], true)) {
				$tokenInfo = self::refreshAccessTokenIfNeeded($userId, $authMethod);
				if (!$tokenInfo['success']) {
					return [
						'success' => false,
						'error' => 'OAuth token missing or refresh failed',
						'connection' => [
							'host' => $server,
							'port' => $port,
							'protocol' => $protocol,
							'encryption' => $encryption === false ? 'none' : $encryption,
							'validate_cert' => true,
							'authMethod' => $authMethod,
							'username' => $username
						]
					];
				}
				$authentication = 'oauth';
				$password = $tokenInfo['access_token'];
			}

			$cm = new ClientManager();
			$clientConfig = [
				'host'          => $server,
				'port'          => $port ?: 993,
				'protocol'      => $protocol,
				'encryption'    => $encryption,
				'validate_cert' => true,
				'username'      => $username,
				'password'      => $password,
				'authentication' => $authentication,
			];
			$clientConfig = array_filter($clientConfig, function($v) { return $v !== null; });
			$client = $cm->make($clientConfig);
			$client->connect();

			$details = [
				'connected' => true,
				'foldersCount' => null,
				'inboxAccessible' => null
			];
			if ($protocol === 'imap') {
				try {
					$folders = $client->getFolders();
					$details['foldersCount'] = method_exists($folders, 'count') ? $folders->count() : (is_array($folders) ? count($folders) : null);
				} catch (\Throwable $e) {
					$details['foldersCount'] = null;
				}
				try {
					$inbox = $client->getFolder('INBOX');
					$details['inboxAccessible'] = $inbox ? true : false;
				} catch (\Throwable $e) {
					$details['inboxAccessible'] = false;
				}
			}

			return [
				'success' => true,
				'connection' => [
					'host' => $server,
					'port' => $port,
					'protocol' => $protocol,
					'encryption' => $encryption === false ? 'none' : $encryption,
					'validate_cert' => true,
					'authMethod' => $authMethod !== '' ? $authMethod : 'password',
					'username' => $username
				],
				'details' => $details
			];
		} catch (\Throwable $e) {
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'connection' => [
					'host' => (string)($params['server'] ?? ''),
					'port' => (int)($params['port'] ?? 0),
					'protocol' => strtolower(trim((string)($params['protocol'] ?? 'imap'))),
					'encryption' => strtolower(trim((string)($params['security'] ?? 'ssl'))),
					'validate_cert' => true,
					'authMethod' => (string)($params['authMethod'] ?? ''),
					'username' => (string)($params['username'] ?? '')
				]
			];
		}
	}

	/**
	 * Refresh access token if expired, return current access token
	 * @param string $authMethod 'oauth_google'|'oauth_microsoft'
	 * @return array{success:bool,access_token?:string,error?:string}
	 */
	private static function refreshAccessTokenIfNeeded(int $userId, string $authMethod): array {
		try {
			$rows = self::getOAuthRow($userId);
			if (empty($rows)) { return ['success' => false, 'error' => 'No OAuth rows']; }
			$now = time();
			$expiresAt = isset($rows['expires_at']) ? (int)$rows['expires_at'] : 0;
			// Google stored access_token as JSON blob (because we keep extra fields)
			if ($authMethod === 'oauth_google') {
				$tokenBlob = $rows['access_token'] ?? '';
				$tokenArr = @json_decode($tokenBlob, true);
				$access = null;
				$refresh = $rows['refresh_token'] ?? ($tokenArr['refresh_token'] ?? '');
				if ($expiresAt > 0 && $now >= $expiresAt && $refresh !== '') {
					require_once(__DIR__ . '/_oauth.php');
					$redirect = $_SESSION['mail_oauth_redirect'] ?? ($GLOBALS['baseUrl'].'api.php');
					$client = OAuthConfig::createGoogleClient($redirect, ['https://mail.google.com/']);
					$client->fetchAccessTokenWithRefreshToken($refresh);
					$new = $client->getAccessToken();
					$access = $new['access_token'] ?? '';
					$exp = 0;
					if (isset($new['created']) && isset($new['expires_in'])) { $exp = ((int)$new['created']) + ((int)$new['expires_in']); }
					if ($access !== '') { self::saveOAuthRow($userId, 'access_token', json_encode($new)); }
					if ($exp > 0) { self::saveOAuthRow($userId, 'expires_at', (string)$exp); }
				} else {
					$access = $tokenArr['access_token'] ?? '';
				}
				if (!$access) { return ['success' => false, 'error' => 'No access token']; }
				return ['success' => true, 'access_token' => $access];
			}
			if ($authMethod === 'oauth_microsoft') {
				$access = $rows['access_token'] ?? '';
				$refresh = $rows['refresh_token'] ?? '';
				if ($expiresAt > 0 && $now >= $expiresAt && $refresh !== '') {
					$tenant = getenv('MS_OAUTH_TENANT') ?: 'common';
					$clientId = getenv('MS_OAUTH_CLIENT_ID') ?: '';
					$clientSecret = getenv('MS_OAUTH_CLIENT_SECRET') ?: '';
					$ms = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft([
						'clientId' => $clientId,
						'clientSecret' => $clientSecret,
						'redirectUri' => $_SESSION['mail_oauth_redirect'] ?? ($GLOBALS['baseUrl'].'api.php'),
						'tenant' => $tenant
					]);
					$new = $ms->getAccessToken('refresh_token', [ 'refresh_token' => $refresh ]);
					$access = $new->getToken();
					if ($access) { self::saveOAuthRow($userId, 'access_token', $access); }
					if ($new->getExpires()) { self::saveOAuthRow($userId, 'expires_at', (string)$new->getExpires()); }
				}
				if (!$access) { return ['success' => false, 'error' => 'No access token']; }
				return ['success' => true, 'access_token' => $access];
			}
			return ['success' => false, 'error' => 'Unsupported auth method'];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * List messages from INBOX since the last successful fetch for the given user.
	 * Persists `last_seen` timestamp under BGROUP `mailhandler_state` for the user.
	 *
	 * @param int $userId
	 * @param int $fallbackWindowSeconds If no state exists, how far back to fetch (default 1 day)
	 * @return array{success:bool, error:?string, client:?\Webklex\PHPIMAP\Client, folder:?Folder, messages:?\Webklex\PHPIMAP\Support\MessageCollection, last_seen:int}
	 */
	public static function imapListSinceLast(int $userId, int $fallbackWindowSeconds = 86400): array {
		$ret = ['success' => false, 'error' => null, 'client' => null, 'folder' => null, 'messages' => null, 'last_seen' => 0];
		$login = self::imapConnectForUser($userId);
		if (!$login['success']) { $ret['error'] = $login['error']; return $ret; }
		$client = $login['client'];
		$ret['client'] = $client;
		try {
			$folder = $client->getFolder('INBOX');
			$ret['folder'] = $folder;
			// Load last_seen
			$stateSQL = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen' LIMIT 1";
			$stateRes = DB::Query($stateSQL);
			$stateRow = DB::FetchArr($stateRes);
			$lastSeenTs = 0;
			if ($stateRow && is_numeric($stateRow['BVALUE'])) { $lastSeenTs = (int)$stateRow['BVALUE']; }
			if ($lastSeenTs <= 0) { $lastSeenTs = time() - max(60, $fallbackWindowSeconds); }
			$ret['last_seen'] = $lastSeenTs;
			$since = Carbon::createFromTimestamp($lastSeenTs);
			$messages = $folder->messages()->since($since)->leaveUnread()->get();
			$ret['messages'] = $messages;
			$ret['success'] = true;
			return $ret;
		} catch (\Throwable $e) {
			$ret['error'] = $e->getMessage();
			return $ret;
		}
	}

	/**
	 * Mark and expunge-delete a message from the server.
	 *
	 * @param \Webklex\PHPIMAP\Message $message
	 * @return bool True on success
	 */
	public static function imapDeleteMessage($message): bool {
		try {
			if ($message === null) { return false; }
			$message->delete();
			$folder = method_exists($message, 'getFolder') ? $message->getFolder() : null;
			if ($folder && method_exists($folder, 'expunge')) { $folder->expunge(); }
			return true;
		} catch (\Throwable $e) {
			if ($GLOBALS['debug'] ?? false) { error_log('imapDeleteMessage error: '.$e->getMessage()); }
			return false;
		}
	}

	/**
	 * Forward a message to a target address via internal mailer.
	 * Uses `_mymail()` and sets Reply-To to the original sender. If the mail has attachments,
	 * the first attachment is forwarded as a file attachment (subsequent ones are ignored for now).
	 *
	 * @param \Webklex\PHPIMAP\Message $message The source message
	 * @param string $targetEmail Target recipient email
	 * @param string $targetName Optional recipient name
	 * @return bool True if sent
	 */
	public static function imapForwardMessage($message, string $targetEmail, string $targetName = ''): bool {
		try {
			if ($message === null || trim($targetEmail) === '') { return false; }
			$origSubject = trim((string)$message->getSubject());
			$subject = (strlen($origSubject) > 0 ? 'Fwd: '.$origSubject : 'Fwd: (no subject)');
			$html = (string)$message->getHTMLBody();
			$plain = (string)$message->getTextBody();
			if ($html === '' && $plain !== '') { $html = nl2br($plain); }
			if ($plain === '' && $html !== '') { $plain = strip_tags($html); }
			$fromAddresses = $message->getFrom();
			$replyTo = '';
			if (is_array($fromAddresses) && count($fromAddresses) > 0) {
				$first = $fromAddresses[0];
				$replyTo = trim(($first->mail ?? ''));
			}
			$attachPath = '';
			$attachments = $message->getAttachments();
			if ($attachments && $attachments->count() > 0) {
				// Save only the first attachment to a temp file for forwarding
				$att = $attachments->first();
				$tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
				$fname = $att->getName() ?: ('attach_'.time());
				$target = $tmpDir.$fname;
				try { $att->save($tmpDir, $fname); $attachPath = $target; } catch (\Throwable $e) { $attachPath = ''; }
			}
			// Build recipient with optional name
			$to = $targetEmail.(strlen($targetName)>0 ? ';'.$targetName : '');
			$from = 'noreply@synaplan.com;Synaplan Mailhandler';
			$ok = _mymail($from, $to, $subject, $html, $plain, $replyTo, $attachPath);
			if ($attachPath !== '' && file_exists($attachPath)) { @unlink($attachPath); }
			return (bool)$ok;
		} catch (\Throwable $e) {
			if ($GLOBALS['debug'] ?? false) { error_log('imapForwardMessage error: '.$e->getMessage()); }
			return false;
		}
	}

	/**
	 * Update the user's mailhandler state `last_seen` to now.
	 *
	 * @param int $userId
	 * @return void
	 */
	public static function imapUpdateLastSeen(int $userId): void {
		$userId = max(0, (int)$userId);
		if ($userId <= 0) { return; }
		$now = time();
		$check = "SELECT BID FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen'";
		$res = DB::Query($check);
		if (DB::CountRows($res) > 0) {
			DB::Query("UPDATE BCONFIG SET BVALUE='".$now."' WHERE BOWNERID= ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen'");
		} else {
			DB::Query("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (".$userId.", 'mailhandler_state', 'last_seen', '".$now."')");
		}
	}

	// ----------------------------------------------------------------------------------
	// Departments helpers
	// ----------------------------------------------------------------------------------
	public static function getDepartmentsForUser(int $userId): array {
		$userId = max(0, (int)$userId);
		$list = [];
		if ($userId <= 0) { return $list; }
		$sql = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP = 'mailhandler_dept' ORDER BY CAST(BSETTING AS UNSIGNED) ASC";
		$res = DB::Query($sql);
		while ($row = DB::FetchArr($res)) {
			$parts = explode('|', $row['BVALUE']);
			$email = trim($parts[0] ?? '');
			$description = trim($parts[1] ?? '');
			$isDefault = (($parts[2] ?? '0') === '1');
			if ($email !== '') {
				$list[] = [ 'email' => $email, 'description' => $description, 'default' => $isDefault ? 1 : 0 ];
			}
		}
		return $list;
	}

	private static function getDefaultDepartmentEmail(array $departments): string {
		foreach ($departments as $d) { if (!empty($d['default'])) { return $d['email']; } }
		return count($departments) > 0 ? ($departments[0]['email'] ?? '') : '';
	}

	private static function extractEmailFromText(string $text): string {
		$pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';
		if (preg_match($pattern, $text, $m)) { return strtolower(trim($m[0])); }
		return '';
	}

	private static function formatSender($message): string {
		try {
			$from = $message->getFrom();
			if (is_array($from) && count($from) > 0) {
				$first = $from[0];
				$name = trim((string)($first->personal ?? $first->personalName ?? ''));
				$email = trim((string)($first->mail ?? ''));
				if ($name !== '' && $email !== '') { return $name.' <'.$email.'>'; }
				if ($email !== '') { return $email; }
			}
		} catch (\Throwable $e) {}
		return '';
	}

	private static function getPlainBody($message): string {
		try {
			$plain = (string)$message->getTextBody();
			$html = (string)$message->getHTMLBody();
			if ($plain === '' && $html !== '') { $plain = strip_tags($html); }
			return trim($plain);
		} catch (\Throwable $e) { return ''; }
	}

	private static function getMessageUnixTime($message): int {
		try {
			$date = $message->getDate();
			if ($date instanceof \Carbon\Carbon) { return $date->getTimestamp(); }
			if (is_string($date) && strlen($date) > 0) { $ts = strtotime($date); if ($ts) { return (int)$ts; } }
		} catch (\Throwable $e) {}
		return time();
	}

	/**
	 * Forward message with ALL attachments to target email.
	 */
	public static function imapForwardMessageAll($message, string $targetEmail, string $targetName = ''): bool {
		try {
			if ($message === null || trim($targetEmail) === '') { return false; }
			$origSubject = trim((string)$message->getSubject());
			$subject = (strlen($origSubject) > 0 ? 'Fwd: '.$origSubject : 'Fwd: (no subject)');
			$html = (string)$message->getHTMLBody();
			$plain = (string)$message->getTextBody();
			if ($html === '' && $plain !== '') { $html = nl2br($plain); }
			if ($plain === '' && $html !== '') { $plain = strip_tags($html); }
			$fromAddresses = $message->getFrom();
			$replyTo = '';
			if (is_array($fromAddresses) && count($fromAddresses) > 0) {
				$first = $fromAddresses[0];
				$replyTo = trim(($first->mail ?? ''));
			}
			$attachPaths = [];
			$attachments = $message->getAttachments();
			if ($attachments && $attachments->count() > 0) {
				$tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
				foreach ($attachments as $att) {
					$fname = $att->getName() ?: ('attach_'.time());
					$target = $tmpDir.$fname;
					try { $att->save($tmpDir, $fname); $attachPaths[] = $target; } catch (\Throwable $e) {}
				}
			}
			$to = $targetEmail.(strlen($targetName)>0 ? ';'.$targetName : '');
			$from = 'noreply@synaplan.com;Synaplan Mailhandler';
			$ok = _mymail($from, $to, $subject, $html, $plain, $replyTo, $attachPaths);
			// cleanup
			foreach ($attachPaths as $p) { if ($p !== '' && file_exists($p)) { @unlink($p); } }
			return (bool)$ok;
		} catch (\Throwable $e) { return false; }
	}

	/**
	 * Process new emails for a user: fetch, route, forward, and update last_seen.
	 */
	public static function processNewEmailsForUser(int $userId, int $maxOnFirstRun = 25): array {
		$userId = max(0, (int)$userId);
		$ret = ['success' => false, 'processed' => 0, 'errors' => []];
		try {
			$login = self::imapConnectForUser($userId);
			if (!$login['success']) { return ['success' => false, 'processed' => 0, 'errors' => [$login['error'] ?? 'login failed']]; }
			$client = $login['client'];
			$folder = $client->getFolder('INBOX');
			// Load last_seen
			$stateSQL = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen' LIMIT 1";
			$stateRes = DB::Query($stateSQL);
			$stateRow = DB::FetchArr($stateRes);
			$lastSeenTs = 0;
			if ($stateRow && is_numeric($stateRow['BVALUE'])) { $lastSeenTs = (int)$stateRow['BVALUE']; }
			Tools::debugCronLog("[IMAP] processNewEmailsForUser userId=".$userId." last_seen=".$lastSeenTs." maxOnFirstRun=".$maxOnFirstRun."\n");
			$messages = null;
			try {
				// Use the simplest compatible query for Gmail: ALL
				$messages = $folder->messages()->all()->get();
				try { $cnt = method_exists($messages,'count') ? $messages->count() : (is_array($messages)?count($messages):0); } catch (\Throwable $eCnt) { $cnt = 0; }
				Tools::debugCronLog("[IMAP] Primary fetch: all()->get() count=".$cnt."\n");
			} catch (\Throwable $e) {
				Tools::debugCronLog("[IMAP] Fetch error (primary all): ".$e->getMessage()."\n");
				try {
					// Minimalistic fallback
					$messages = $folder->messages()->get();
					try { $cnt = method_exists($messages,'count') ? $messages->count() : (is_array($messages)?count($messages):0); } catch (\Throwable $eCnt) { $cnt = 0; }
					Tools::debugCronLog("[IMAP] Fallback fetch: messages()->get() count=".$cnt."\n");
				} catch (\Throwable $e2) {
					Tools::debugCronLog("[IMAP] Fetch error (fallback get): ".$e2->getMessage()."\n");
					return ['success' => false, 'processed' => 0, 'errors' => ['fetch_all: '.$e->getMessage(), 'fetch_get: '.$e2->getMessage()]];
				}
			}
			if (!$messages) { return ['success' => true, 'processed' => 0, 'errors' => []]; }
			// Collect and ensure newest first; apply last_seen filter in PHP to avoid server-specific syntax issues
			$collected = [];
			foreach ($messages as $m) { $collected[] = $m; }
			if ($lastSeenTs > 0) {
				$collected = array_values(array_filter($collected, function($msg) use ($lastSeenTs) {
					return self::getMessageUnixTime($msg) > $lastSeenTs;
				}));
			}
			usort($collected, function($a, $b) { return self::getMessageUnixTime($b) <=> self::getMessageUnixTime($a); });
			// If first run and we couldn't apply a limit on server side, apply here
			if ($lastSeenTs <= 0 && $maxOnFirstRun > 0 && count($collected) > $maxOnFirstRun) {
				$collected = array_slice($collected, 0, $maxOnFirstRun);
			}
			Tools::debugCronLog("[IMAP] Collected messages after sort/limit: ".count($collected)."\n");
			$departments = self::getDepartmentsForUser($userId);
			$allowedEmails = array_map(function($d){ return strtolower($d['email']); }, $departments);
			$defaultEmail = self::getDefaultDepartmentEmail($departments);
			Tools::debugCronLog("[ROUTING] Allowed targets: ".implode(',', $allowedEmails)." default=".strtolower($defaultEmail)."\n");
			$latestTs = $lastSeenTs;
			$processed = 0;
			foreach ($collected as $msg) {
				try {
					$sender = self::formatSender($msg);
					$subject = trim((string)$msg->getSubject());
					$body = self::getPlainBody($msg);
					// Append attachments list to body for AI context
					$attachments = $msg->getAttachments();
					$attachNames = [];
					if ($attachments && $attachments->count() > 0) {
						foreach ($attachments as $att) { $attachNames[] = $att->getName() ?: 'attachment'; }
					}
					$attachmentSection = '';
					if (count($attachNames) > 0) {
						$attachmentSection = "\n\nAttachments (".count($attachNames)."):\n- ".implode("\n- ", $attachNames);
					}
					$aiBody = "SENDER: ".$sender."\nSUBJECT: ".$subject."\n\n".$body.$attachmentSection;
					Tools::debugCronLog("[MSG] subject=\"".substr($subject,0,120)."\" from=\"".substr($sender,0,120)."\" attachments=".count($attachNames)."\n");
					// Run routing AI
					$aiAnswer = self::runRoutingForUser($userId, $subject, $aiBody);
					$chosen = self::extractEmailFromText(is_array($aiAnswer) ? json_encode($aiAnswer) : (string)$aiAnswer);
					$chosen = strtolower($chosen);
					Tools::debugCronLog("[ROUTING] aiAnswer=\"".substr((string)$aiAnswer,0,160)."\" parsed=\"".$chosen."\"\n");
					if ($chosen === '' || !in_array($chosen, $allowedEmails, true)) { $chosen = strtolower($defaultEmail); }
					Tools::debugCronLog("[ROUTING] chosenTarget=\"".$chosen."\"\n");
					// Forward with all attachments
					$sentOk = self::imapForwardMessageAll($msg, $chosen, '');
					Tools::debugCronLog("[FORWARD] sent=".($sentOk?'1':'0')." to=\"".$chosen."\"\n");
					// Mark read rules: mark as read unless default was selected
					if ($chosen !== '' && $chosen !== strtolower($defaultEmail)) {
						try {
							// set seen via flags API; ignore failures
							if (method_exists($msg, 'setFlag')) { $msg->setFlag('Seen'); }
							if (method_exists($msg, 'setFlags')) { $msg->setFlags(['Seen']); }
							if (method_exists($msg, 'markAsRead')) { $msg->markAsRead(); }
						} catch (\Throwable $e) {}
					}
					$ts = self::getMessageUnixTime($msg);
					if ($ts > $latestTs) { $latestTs = $ts; }
					$processed++;
				} catch (\Throwable $e) {
					$ret['errors'][] = 'process_message: '.$e->getMessage();
				}
			}
			if ($processed > 0 && $latestTs > 0) {
				// Persist last_seen to latestTs
				$userId = max(0, (int)$userId);
				$check = "SELECT BID FROM BCONFIG WHERE BOWNERID = ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen'";
				$res = DB::Query($check);
				if (DB::CountRows($res) > 0) {
					DB::Query("UPDATE BCONFIG SET BVALUE='".$latestTs."' WHERE BOWNERID= ".$userId." AND BGROUP='mailhandler_state' AND BSETTING='last_seen'");
				} else {
					DB::Query("INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (".$userId.", 'mailhandler_state', 'last_seen', '".$latestTs."')");
				}
				Tools::debugCronLog("[STATE] Updated last_seen to ".$latestTs."\n");
			}
			try { $client->disconnect(); } catch (\Throwable $e) {}
			$ret['success'] = true;
			$ret['processed'] = $processed;
			return $ret;
		} catch (\Throwable $e) {
			$ret['errors'][] = 'fatal: '.$e->getMessage();
			return $ret;
		}
	}
}


