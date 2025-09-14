<?php

class Rasterizer {
	public static string $lastEngine = '';
	public static int $lastDpi = 0;
	public static int $lastPages = 0;
	/**
	 * Rasterize PDF to PNG images and return absolute paths of generated PNGs.
	 * Tries Imagick first, then falls back to pdftoppm.
	 */
	public static function pdfToPng(string $absolutePdfPath): array {
		$dpi = ApiKeys::getRasterizeDpi();
		$pageCap = ApiKeys::getRasterizePageCap();
		$timeoutMs = ApiKeys::getRasterizeTimeoutMs();

		if (!is_file($absolutePdfPath) || filesize($absolutePdfPath) === 0) {
			return [];
		}
		$targetDir = self::resolveTargetDir($absolutePdfPath);
		$basename = pathinfo($absolutePdfPath, PATHINFO_FILENAME);

		$images = [];
		// Try Imagick if available
		if (class_exists('\\Imagick')) {
			try {
				$className = 'Imagick';
				$im = new $className();
				$im->setResolution($dpi, $dpi);
				$im->readImage($absolutePdfPath);
				$pages = min($pageCap, $im->getNumberImages());
				$im->setIteratorIndex(0);
				for ($i = 0; $i < $pages; $i++) {
					$im->setIteratorIndex($i);
					$im->setImageFormat('png');
					$out = $targetDir . '/' . $basename . '-' . ($i+1) . '.png';
					$ok = $im->writeImage($out);
					if ($ok && is_file($out) && filesize($out) > 0) {
						$images[] = $out;
					} else {
						@error_log('Rasterizer Imagick write failed for ' . $out);
					}
				}
				$im->clear();
				$im->destroy();
				if (!empty($images)) {
					self::$lastEngine = 'imagick';
					self::$lastDpi = (int)$dpi;
					self::$lastPages = (int)count($images);
					@error_log('Rasterizer: engine=imagick pages=' . self::$lastPages . ' dpi=' . self::$lastDpi);
					return $images;
				}
			} catch (\Throwable $e) {
				@error_log('Rasterizer Imagick fallback: ' . $e->getMessage());
			}
		}

		// Fallback to pdftoppm
		$prefix = $targetDir . '/' . $basename;
		$cmd = 'pdftoppm -png -r ' . (int)$dpi . ' -f 1 -l ' . (int)$pageCap . ' ' . escapeshellarg($absolutePdfPath) . ' ' . escapeshellarg($prefix);
		self::execWithTimeout($cmd, $timeoutMs);
		for ($i = 1; $i <= $pageCap; $i++) {
			$file = $prefix . '-' . $i . '.png';
			if (is_file($file) && filesize($file) > 0) { $images[] = $file; }
		}
		if (!empty($images)) {
			self::$lastEngine = 'pdftoppm';
			self::$lastDpi = (int)$dpi;
			self::$lastPages = (int)count($images);
			@error_log('Rasterizer: engine=pdftoppm pages=' . self::$lastPages . ' dpi=' . self::$lastDpi);
		}
		return $images;
	}

	private static function ensureTempDir(): string {
		$dir = rtrim(UPLOAD_DIR, '/') . '/tmp';
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		return $dir;
	}

	private static function resolveTargetDir(string $absolutePdfPath): string {
		$uploadBase = rtrim(UPLOAD_DIR, '/') . '/';
		// If the PDF is already inside the public uploads tree, write PNGs next to it
		if (strpos($absolutePdfPath, $uploadBase) === 0) {
			$dir = dirname($absolutePdfPath);
			if (!is_dir($dir)) @mkdir($dir, 0755, true);
			return $dir;
		}
		// Otherwise, fall back to a temp folder under uploads
		return self::ensureTempDir();
	}

	private static function execWithTimeout(string $cmd, int $timeoutMs): void {
		$timeoutSec = max(1, (int)ceil($timeoutMs / 1000));
		$full = 'timeout ' . $timeoutSec . 's ' . $cmd . ' 2>&1';
		@exec($full, $out, $code);
		if ($code !== 0) {
			@error_log('Rasterizer exec failed code=' . $code . ' cmd=' . $cmd);
		}
	}
}


