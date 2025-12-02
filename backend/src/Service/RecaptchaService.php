<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use ReCaptcha\ReCaptcha;

class RecaptchaService
{
    private bool $enabled;
    private ?ReCaptcha $recaptcha;
    private float $minScore;

    public function __construct(
        private LoggerInterface $logger,
        string $secretKey,
        string $enabled = 'false',
        string $minScore = '0.5'
    ) {
        $this->enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        $this->minScore = (float) $minScore;
        
        if ($this->enabled && !empty($secretKey) && $secretKey !== 'your_secret_key_here') {
            $this->recaptcha = new ReCaptcha($secretKey);
            $this->logger->info('reCAPTCHA v3 enabled', [
                'min_score' => $this->minScore
            ]);
        } else {
            $this->recaptcha = null;
            $this->logger->info('reCAPTCHA v3 disabled (dev mode or not configured)');
        }
    }

    /**
     * Verify reCAPTCHA token
     * Returns true if verification passes OR if reCAPTCHA is disabled (dev mode)
     */
    public function verify(string $token, string $action, ?string $remoteIp = null): bool
    {
        // If reCAPTCHA is disabled (dev mode), always return true
        if (!$this->enabled || !$this->recaptcha) {
            $this->logger->debug('reCAPTCHA verification skipped (disabled)');
            return true;
        }

        // Verify token
        try {
            $response = $this->recaptcha->setExpectedAction($action)
                                        ->setScoreThreshold($this->minScore)
                                        ->verify($token, $remoteIp);

            if ($response->isSuccess()) {
                $score = $response->getScore();
                $this->logger->info('reCAPTCHA verification successful', [
                    'action' => $action,
                    'score' => $score,
                    'threshold' => $this->minScore
                ]);
                return true;
            }

            $this->logger->warning('reCAPTCHA verification failed', [
                'action' => $action,
                'errors' => $response->getErrorCodes(),
                'score' => $response->getScore()
            ]);
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('reCAPTCHA verification error', [
                'error' => $e->getMessage()
            ]);
            
            // In case of API error, allow request (fail-open for better UX)
            return true;
        }
    }

    /**
     * Check if reCAPTCHA is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->recaptcha !== null;
    }
}

