<?php

class TikaClient {
    private static $didHealthCheck = false;
	/**
	 * Resolve Tika base URL from environment with documented default.
	 */
	public static function resolveBaseUrl(): string {
		$envUrl = ApiKeys::getTikaUrl();
		$base = $envUrl && strlen(trim($envUrl)) > 0 ? trim($envUrl) : 'http://tika:9998';
		return rtrim($base, '/');
	}

	/**
	 * Extract plain text via Apache Tika. Returns [text, meta] or [null, meta] on failure.
	 */
	public static function extractText(string $absoluteFilePath, ?string $mimeType = null): array {
		$baseUrl = self::resolveBaseUrl();
		$endpoint = $baseUrl . '/tika';
		$timeoutMs = ApiKeys::getTikaTimeoutMs();
		$retries = ApiKeys::getTikaRetries();
		$backoffMs = ApiKeys::getTikaRetryBackoffMs();

		// One-time lightweight health check
		self::maybePingHealth($baseUrl);

		$headers = [
			'Accept: text/plain',
			'User-Agent: synaplan-tika-client'
		];
		if ($mimeType) {
			$headers[] = 'Content-Type: ' . $mimeType;
		}
		// Avoid 100-continue delay
		$headers[] = 'Expect:';

		// Debug pre-call info
		if (!empty($GLOBALS['debug'])) {
			$sanitized = self::sanitizeUrl($endpoint);
			$size = is_file($absoluteFilePath) ? filesize($absoluteFilePath) : 0;
			@error_log('Tika pre-call endpoint=' . $sanitized . ' ct=' . ($mimeType ?: '') . ' size=' . $size . ' file=' . basename($absoluteFilePath));
		}

		$attempt = 0;
		$startTs = microtime(true);
		$lastError = '';
		while ($attempt <= $retries) {
			$attempt++;
			try {
				list($result, $httpCode) = self::curlPutFile($endpoint, $headers, $absoluteFilePath, $timeoutMs);
				$elapsedMs = (int)((microtime(true) - $startTs) * 1000);
				self::logCall($endpoint, $attempt, true, $elapsedMs, strlen($result), '', $httpCode);
				return [$result, ['endpoint' => $baseUrl, 'attempts' => $attempt, 'elapsed_ms' => $elapsedMs, 'http' => $httpCode]];
			} catch (\Throwable $e) {
				$lastError = $e->getMessage();
				$elapsedMs = (int)((microtime(true) - $startTs) * 1000);
				self::logCall($endpoint, $attempt, false, $elapsedMs, 0, $lastError, 0);
				if ($attempt <= $retries && $backoffMs > 0) {
					usleep($backoffMs * 1000);
				}
			}
		}

		return [null, ['endpoint' => $baseUrl, 'error' => $lastError]];
	}

	private static function curlPutFile(string $url, array $headers, string $filePath, int $timeoutMs): array {
		if (!is_file($filePath) || filesize($filePath) === 0) {
			throw new \RuntimeException('Input file missing or empty: ' . $filePath);
		}
		$fp = fopen($filePath, 'rb');
		if (!$fp) {
			throw new \RuntimeException('Unable to open file: ' . $filePath);
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_PUT, true);
		curl_setopt($ch, CURLOPT_INFILE, $fp);
		curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
		if (function_exists('curl_setopt') && defined('CURLOPT_TIMEOUT_MS')) {
			curl_setopt($ch, CURLOPT_TIMEOUT_MS, max(1000, $timeoutMs));
		} else {
			curl_setopt($ch, CURLOPT_TIMEOUT, max(1, (int)ceil($timeoutMs / 1000)));
		}
		$response = curl_exec($ch);
		$errNo = curl_errno($ch);
		$err = $errNo ? curl_error($ch) : '';
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if ($errNo) {
			throw new \RuntimeException('Curl error: ' . $err);
		}
		if ($httpCode < 200 || $httpCode >= 300) {
			throw new \RuntimeException('HTTP ' . $httpCode . ' from Tika');
		}
		if (!is_string($response)) {
			throw new \RuntimeException('Empty response from Tika');
		}
		return [$response, $httpCode];
	}

	private static function logCall(string $endpoint, int $attempt, bool $success, int $elapsedMs, int $bytes, string $error = '', int $httpCode = 0): void {
		// Sanitize URL (strip credentials)
		$sanitized = self::sanitizeUrl($endpoint);
		$msg = 'TikaCall endpoint=' . $sanitized . ' attempt=' . $attempt . ' success=' . ($success ? '1' : '0') . ' http=' . $httpCode . ' elapsed_ms=' . $elapsedMs . ' bytes=' . $bytes;
		if (!$success && $error) { $msg .= ' error=' . $error; }
		if (!empty($GLOBALS['debug'])) { @error_log($msg); }
	}

	public static function sanitizeUrl(string $url): string {
		$parts = parse_url($url);
		if ($parts === false) { return $url; }
		$scheme = $parts['scheme'] ?? 'http';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? (':' . $parts['port']) : '';
		$path = $parts['path'] ?? '';
		return $scheme . '://' . $host . $port . $path;
	}

	private static function maybePingHealth(string $baseUrl): void {
		if (self::$didHealthCheck) return;
		self::$didHealthCheck = true;
		$healthUrl = rtrim($baseUrl, '/') . '/version';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $healthUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		$start = microtime(true);
		$response = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if (!empty($GLOBALS['debug'])) {
			$elapsed = (int)((microtime(true) - $start) * 1000);
			$san = self::sanitizeUrl($healthUrl);
			@error_log('Tika health endpoint=' . $san . ' http=' . $code . ' elapsed_ms=' . $elapsed);
		}
	}
}


