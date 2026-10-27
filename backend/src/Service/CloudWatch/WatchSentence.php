<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;

/**
 * The one sentence a watch shows. English is the contract text; the cloud
 * apps display it as Synaplan sends it.
 */
final class WatchSentence
{
    public function for(CloudWatch $watch, ?string $taskName = null): string
    {
        $path = '' !== $watch->getTargetPath() ? $watch->getTargetPath() : $watch->getTargetId();
        $file = CloudWatch::KIND_FILE === $watch->getTargetKind();

        return match ($watch->getState()) {
            CloudWatch::STATE_PAUSED => match ($watch->getReason()) {
                CloudWatch::REASON_IDENTITY_OFF => 'Paused — Nextcloud is disconnected from your Synaplan account',
                CloudWatch::REASON_PLATFORM_USER_DISABLED => 'Paused — your Nextcloud account is disabled',
                CloudWatch::REASON_PERMISSION => 'Paused — Synaplan needs your permission to watch folders',
                default => 'Paused by you',
            },
            CloudWatch::STATE_ATTENTION => match ($watch->getReason()) {
                CloudWatch::REASON_NO_ACCESS => $file ? 'Stopped — you can no longer open this file' : 'Stopped — you can no longer open this folder',
                CloudWatch::REASON_TASK_GONE => 'Stopped — the task it ran was deleted',
                CloudWatch::REASON_POLICY_OFF => 'Stopped — your administrator turned off watching shared folders',
                CloudWatch::REASON_FAILING => 'Stopped after 5 failed files in a row',
                default => $file ? 'Stopped — the file was deleted' : 'Stopped — the folder was deleted',
            },
            CloudWatch::STATE_STOPPED => '',
            default => $this->active($watch, $path, $taskName),
        };
    }

    private function active(CloudWatch $watch, string $path, ?string $taskName): string
    {
        if ($watch->isSearchable()) {
            $folder = $watch->getGroupKey() ?? $path;

            return 'New and changed files become searchable by AI in your knowledge folder “'.$folder.'”.';
        }
        $name = $taskName ?? 'The task';

        return '“'.$name.'” will run for every new file in '.$path.'.';
    }
}
