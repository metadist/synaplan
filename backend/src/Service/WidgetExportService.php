<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Service for exporting widget chat data in various formats.
 *
 * Supports: Excel (primary), CSV, JSON
 */
final class WidgetExportService
{
    public function __construct(
        private WidgetSessionRepository $sessionRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
    ) {
    }

    /**
     * Export widget sessions to Excel format.
     *
     * @param array{from?: int, to?: int, mode?: string} $filters
     */
    public function exportToExcel(Widget $widget, array $filters = []): string
    {
        $spreadsheet = new Spreadsheet();

        // Sheet 1: Overview
        $overviewSheet = $spreadsheet->getActiveSheet();
        $overviewSheet->setTitle('Overview');
        $this->createOverviewSheet($overviewSheet, $widget, $filters);

        // Sheet 2: Conversations
        $conversationsSheet = $spreadsheet->createSheet();
        $conversationsSheet->setTitle('Conversations');
        $this->createConversationsSheet($conversationsSheet, $widget, $filters);

        // Sheet 3: Sessions Summary
        $sessionsSheet = $spreadsheet->createSheet();
        $sessionsSheet->setTitle('Sessions');
        $this->createSessionsSheet($sessionsSheet, $widget, $filters);

        // Generate file
        $tempFile = tempnam(sys_get_temp_dir(), 'widget_export_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Export widget sessions to CSV format.
     *
     * @param array{from?: int, to?: int, mode?: string} $filters
     */
    public function exportToCsv(Widget $widget, array $filters = []): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'widget_export_').'.csv';
        $handle = fopen($tempFile, 'w');

        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Header
        fputcsv($handle, [
            'Session ID',
            'Created',
            'Last Activity',
            'Message Count',
            'Mode',
            'Timestamp',
            'Sender',
            'Message',
        ]);

        // Get sessions
        $result = $this->sessionRepository->findSessionsByWidget(
            $widget->getWidgetId(),
            1000,
            0,
            $filters
        );

        foreach ($result['sessions'] as $session) {
            $messages = $this->getSessionMessages($session);
            $messageCount = count($messages);

            foreach ($messages as $message) {
                fputcsv($handle, [
                    $session->getSessionId(),
                    date('Y-m-d H:i:s', $session->getCreated()),
                    date('Y-m-d H:i:s', $session->getLastMessage()),
                    $messageCount,
                    $session->getMode(),
                    date('Y-m-d H:i:s', $message['timestamp']),
                    $message['sender'],
                    $message['text'],
                ]);
            }
        }

        fclose($handle);

        return $tempFile;
    }

    /**
     * Export widget sessions to JSON format.
     *
     * @param array{from?: int, to?: int, mode?: string} $filters
     */
    public function exportToJson(Widget $widget, array $filters = [], string $baseUrl = ''): string
    {
        $result = $this->sessionRepository->findSessionsByWidget(
            $widget->getWidgetId(),
            1000,
            0,
            $filters
        );

        $exportData = [
            'widget' => [
                'id' => $widget->getWidgetId(),
                'name' => $widget->getName(),
                'exported_at' => date('c'),
            ],
            'export_range' => [
                'from' => null,
                'to' => null,
            ],
            'sessions' => [],
            'statistics' => [
                'total_sessions' => $result['total'],
                'total_messages' => 0,
                'avg_messages_per_session' => 0,
            ],
        ];

        $totalMessages = 0;
        $totalFiles = 0;
        $earliestCreated = null;
        $latestActivity = null;

        foreach ($result['sessions'] as $session) {
            $messages = $this->getSessionMessages($session);
            $messageCount = count($messages);
            $fileCount = $this->countFilesInMessages($messages);
            $totalMessages += $messageCount;
            $totalFiles += $fileCount;

            // Track earliest and latest timestamps
            $created = $session->getCreated();
            $lastMessage = $session->getLastMessage();
            if (null === $earliestCreated || $created < $earliestCreated) {
                $earliestCreated = $created;
            }
            if (null === $latestActivity || $lastMessage > $latestActivity) {
                $latestActivity = $lastMessage;
            }

            $exportData['sessions'][] = [
                'session_id' => $session->getSessionId(),
                'created' => date('c', $session->getCreated()),
                'last_activity' => date('c', $session->getLastMessage()),
                'message_count' => $messageCount,
                'file_count' => $fileCount,
                'mode' => $session->getMode(),
                'messages' => array_map(fn ($m) => [
                    'direction' => $m['direction'],
                    'text' => $m['text'],
                    'timestamp' => date('c', $m['timestamp']),
                    'sender' => $m['sender'],
                    'files' => array_map(fn ($f) => [
                        ...$f,
                        'download_url' => $baseUrl.$f['download_url'],
                    ], $m['files']),
                ], $messages),
            ];
        }

        // Set actual date range from exported sessions
        $exportData['export_range']['from'] = $earliestCreated ? date('c', $earliestCreated) : null;
        $exportData['export_range']['to'] = $latestActivity ? date('c', $latestActivity) : null;

        $exportData['statistics']['total_messages'] = $totalMessages;
        $exportData['statistics']['total_files'] = $totalFiles;
        if ($result['total'] > 0) {
            $exportData['statistics']['avg_messages_per_session'] = round($totalMessages / $result['total'], 1);
        }

        // Add mode counts
        $modeCounts = $this->sessionRepository->countSessionsByMode($widget->getWidgetId());
        $exportData['statistics']['ai_sessions'] = $modeCounts['ai'];
        $exportData['statistics']['human_sessions'] = $modeCounts['human'];
        $exportData['statistics']['waiting_sessions'] = $modeCounts['waiting'];

        $tempFile = tempnam(sys_get_temp_dir(), 'widget_export_').'.json';
        file_put_contents($tempFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $tempFile;
    }

    private function createOverviewSheet($sheet, Widget $widget, array $filters): void
    {
        // Title styling
        $sheet->setCellValue('A1', 'Widget Export Report');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Widget info
        $sheet->setCellValue('A3', 'Widget Name:');
        $sheet->setCellValue('B3', $widget->getName());
        $sheet->setCellValue('A4', 'Widget ID:');
        $sheet->setCellValue('B4', $widget->getWidgetId());
        $sheet->setCellValue('A5', 'Export Date:');
        $sheet->setCellValue('B5', date('Y-m-d H:i:s'));

        // Filter info
        $sheet->setCellValue('A7', 'Export Range:');
        $fromDate = isset($filters['from']) ? date('Y-m-d', $filters['from']) : 'All time';
        $toDate = isset($filters['to']) ? date('Y-m-d', $filters['to']) : 'Present';
        $sheet->setCellValue('B7', $fromDate.' to '.$toDate);

        // Statistics
        $result = $this->sessionRepository->findSessionsByWidget($widget->getWidgetId(), 1000, 0, $filters);
        $modeStats = $this->sessionRepository->countSessionsByMode($widget->getWidgetId());

        $sheet->setCellValue('A9', 'Statistics');
        $sheet->getStyle('A9')->getFont()->setBold(true)->setSize(12);

        $sheet->setCellValue('A10', 'Total Sessions:');
        $sheet->setCellValue('B10', $result['total']);
        $sheet->setCellValue('A11', 'AI Sessions:');
        $sheet->setCellValue('B11', $modeStats['ai']);
        $sheet->setCellValue('A12', 'Human Sessions:');
        $sheet->setCellValue('B12', $modeStats['human']);
        $sheet->setCellValue('A13', 'Waiting Sessions:');
        $sheet->setCellValue('B13', $modeStats['waiting']);

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(40);
    }

    private function createConversationsSheet($sheet, Widget $widget, array $filters): void
    {
        // Header
        $headers = ['Session', 'Time', 'From', 'Message'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col.'1', $header);
            $sheet->getStyle($col.'1')->getFont()->setBold(true);
            $sheet->getStyle($col.'1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E2E8F0');
            ++$col;
        }

        // Get sessions and messages
        $result = $this->sessionRepository->findSessionsByWidget($widget->getWidgetId(), 500, 0, $filters);

        $row = 2;
        $sessionNum = 1;
        $lastSessionId = null;

        foreach ($result['sessions'] as $session) {
            $messages = $this->getSessionMessages($session);

            foreach ($messages as $message) {
                // Add separator between sessions
                if (null !== $lastSessionId && $lastSessionId !== $session->getSessionId()) {
                    $sheet->setCellValue('A'.$row, '---');
                    $sheet->setCellValue('B'.$row, '---');
                    $sheet->setCellValue('C'.$row, '---');
                    $sheet->setCellValue('D'.$row, '---');
                    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('999999'));
                    ++$row;
                    ++$sessionNum;
                }

                $sheet->setCellValue('A'.$row, '#'.$sessionNum);
                $sheet->setCellValue('B'.$row, date('H:i:s', $message['timestamp']));
                $sheet->setCellValue('C'.$row, $message['sender']);
                $sheet->setCellValue('D'.$row, $message['text']);

                // Color coding for sender
                if ('IN' === $message['direction']) {
                    $sheet->getStyle('A'.$row.':D'.$row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F0FDF4');
                }

                $lastSessionId = $session->getSessionId();
                ++$row;
            }
        }

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(80);

        // Wrap text in message column
        $sheet->getStyle('D:D')->getAlignment()->setWrapText(true);
    }

    private function createSessionsSheet($sheet, Widget $widget, array $filters): void
    {
        // Header
        $headers = ['Session ID', 'Created', 'Last Activity', 'Messages', 'Files', 'Mode', 'Duration'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col.'1', $header);
            $sheet->getStyle($col.'1')->getFont()->setBold(true);
            $sheet->getStyle($col.'1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E2E8F0');
            ++$col;
        }

        // Get sessions
        $result = $this->sessionRepository->findSessionsByWidget($widget->getWidgetId(), 500, 0, $filters);

        $row = 2;
        foreach ($result['sessions'] as $session) {
            $duration = $session->getLastMessage() - $session->getCreated();
            $durationStr = $this->formatDuration($duration);
            $messages = $this->getSessionMessages($session);
            $messageCount = count($messages);
            $fileCount = $this->countFilesInMessages($messages);

            $sheet->setCellValue('A'.$row, substr($session->getSessionId(), 0, 12).'...');
            $sheet->setCellValue('B'.$row, date('Y-m-d H:i', $session->getCreated()));
            $sheet->setCellValue('C'.$row, date('Y-m-d H:i', $session->getLastMessage()));
            $sheet->setCellValue('D'.$row, $messageCount);
            $sheet->setCellValue('E'.$row, $fileCount);
            $sheet->setCellValue('F'.$row, ucfirst($session->getMode()));
            $sheet->setCellValue('G'.$row, $durationStr);

            ++$row;
        }

        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Get messages for a session.
     *
     * @return array<array{direction: string, text: string, timestamp: int, sender: string, files: array}>
     */
    private function getSessionMessages(WidgetSession $session): array
    {
        $chatId = $session->getChatId();
        if (!$chatId) {
            return [];
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat) {
            return [];
        }

        $messages = $this->messageRepository->findChatHistory(
            $chat->getUserId(),
            $chat->getId(),
            100,
            50000
        );

        return array_map(function ($message) {
            $sender = 'AI';
            if ('IN' === $message->getDirection()) {
                $sender = 'Visitor';
            } elseif ('HUMAN_OPERATOR' === $message->getProviderIndex()) {
                $sender = 'Support';
            }

            // Extract files from message
            $files = [];
            foreach ($message->getFiles() as $file) {
                $files[] = [
                    'id' => $file->getId(),
                    'filename' => $file->getFileName(),
                    'type' => $file->getFileType(),
                    'mime' => $file->getFileMime(),
                    'size' => $file->getFileSize(),
                    'download_url' => '/api/v1/files/'.$file->getId().'/download',
                ];
            }

            return [
                'direction' => $message->getDirection(),
                'text' => $message->getText(),
                'timestamp' => $message->getUnixTimestamp(),
                'sender' => $sender,
                'files' => $files,
            ];
        }, $messages);
    }

    /**
     * Count total files in messages.
     */
    private function countFilesInMessages(array $messages): int
    {
        $count = 0;
        foreach ($messages as $message) {
            $count += count($message['files'] ?? []);
        }

        return $count;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }
}
