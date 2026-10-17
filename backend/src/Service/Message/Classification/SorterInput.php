<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\Context\AttachmentDigest;
use App\Service\Context\TokenEstimator;

/**
 * The message as the AI sorter reads it: the BMESSAGES fields, with a large
 * attachment's text reduced to a structural digest so it fits the small
 * routing model, and the kinds of the attached files.
 */
final readonly class SorterInput
{
    public function __construct(
        private ?AttachmentDigest $attachmentDigest = null,
    ) {
    }

    /**
     * Build message data array for sorter.
     *
     * BFILETEXT is the ROUTING view of the attachment: verbatim for ordinary
     * files, a structural digest for large ones. The sorter only decides
     * what to do with the file; a 250 kB spreadsheet as JSON overflows the
     * (small) routing model and turns every such upload into the fallback
     * topic. The answering handler still receives the full text.
     */
    public function for(Message $message): array
    {
        $fileText = $message->getFileText() ?: '';
        if ('' !== $fileText) {
            $digest = $this->attachmentDigest ?? new AttachmentDigest(new TokenEstimator());
            $fileText = $digest->forRoutingWithConfig($fileText, $message->getUserId(), $message->getFileType());
        }

        $data = [
            'BDATETIME' => $message->getDateTime(),
            'BFILEPATH' => $message->getFilePath(),
            'BTOPIC' => $message->getTopic() ?: '',
            'BLANG' => $message->getLanguage() ?: 'en',
            'BTEXT' => $message->getText(),
            'BFILETEXT' => $fileText,
            'BFILE' => $message->getFile(),
            'BWEBSEARCH' => 0,
            // Intentionally omit BMULTI. The sorter prompt asks the model to
            // set it to 0|1, and TaskPlanExecutor only skips the planner on an
            // *explicit* false. Seeding 0 here would make a lazy/truncated
            // echo of the inbound JSON look like a single-step vote and skip
            // planning — the unsafe fallback the vote was designed to avoid.
        ];

        $fileType = $message->getFileType();
        if ('' !== $fileType) {
            $data['BFILETYPE'] = $fileType;
        }

        $attachedFiles = $message->getFiles();
        if ($attachedFiles->count() > 0) {
            $types = [];
            foreach ($attachedFiles as $file) {
                $types[] = $file->getFileType() ?: $file->getFileMime();
            }
            $data['BATTACHED_FILES'] = implode(', ', $types);
            $data['BATTACHED_COUNT'] = $attachedFiles->count();
        }

        return $data;
    }
}
