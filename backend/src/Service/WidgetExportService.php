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
final readonly class WidgetExportService
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

        $customFields = $widget->getConfig()['customFields'] ?? [];

        // Header
        $headers = [
            'Session ID',
            'Created',
            'Last Activity',
            'Message Count',
            'Mode',
            'Channel',
            'Timestamp',
            'Sender',
            'Language',
            'Message',
        ];
        foreach ($customFields as $field) {
            $headers[] = $this->sanitizeCellValue($field['name']);
        }
        fputcsv($handle, $headers);

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
            $cfValues = $session->getCustomFieldValues() ?? [];

            foreach ($messages as $message) {
                $row = [
                    $session->getSessionId(),
                    date('Y-m-d H:i:s', $session->getCreated()),
                    date('Y-m-d H:i:s', $session->getLastMessage()),
                    $messageCount,
                    $session->getMode(),
                    'Chat Widget',
                    date('Y-m-d H:i:s', $message['timestamp']),
                    $message['sender'],
                    $message['language'],
                    $message['text'],
                ];
                foreach ($customFields as $field) {
                    $val = $cfValues[$field['id']] ?? ('boolean' === $field['type'] ? false : '');
                    $row[] = is_bool($val) ? ($val ? 'Yes' : 'No') : $this->sanitizeCellValue((string) $val);
                }
                fputcsv($handle, $row);
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

        $customFields = $widget->getConfig()['customFields'] ?? [];

        $widgetData = [
            'id' => $widget->getWidgetId(),
            'name' => $widget->getName(),
            'exported_at' => date('c'),
        ];
        if (!empty($customFields)) {
            $widgetData['custom_fields'] = $customFields;
        }

        $exportData = [
            'widget' => $widgetData,
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
        $modeCounts = ['ai' => 0, 'human' => 0, 'waiting' => 0, 'internal' => 0];

        foreach ($result['sessions'] as $session) {
            $messages = $this->getSessionMessages($session);
            $messageCount = count($messages);
            $fileCount = $this->countFilesInMessages($messages);
            $totalMessages += $messageCount;
            $totalFiles += $fileCount;

            // Count modes from exported sessions
            $mode = $session->getMode();
            if (isset($modeCounts[$mode])) {
                ++$modeCounts[$mode];
            } else {
                ++$modeCounts['ai'];
            }

            // Track earliest and latest timestamps
            $created = $session->getCreated();
            $lastMessage = $session->getLastMessage();
            if (null === $earliestCreated || $created < $earliestCreated) {
                $earliestCreated = $created;
            }
            if (null === $latestActivity || $lastMessage > $latestActivity) {
                $latestActivity = $lastMessage;
            }

            $sessionData = [
                'session_id' => $session->getSessionId(),
                'channel' => 'Chat Widget',
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
                    'language' => $m['language'],
                    'files' => array_map(fn ($f) => [
                        ...$f,
                        'download_url' => $baseUrl.$f['download_url'],
                    ], $m['files']),
                ], $messages),
            ];

            $cfValues = $session->getCustomFieldValues();
            if (null !== $cfValues && !empty($cfValues)) {
                $sessionData['custom_field_values'] = $cfValues;
            }

            $exportData['sessions'][] = $sessionData;
        }

        // Set actual date range from exported sessions
        $exportData['export_range']['from'] = $earliestCreated ? date('c', $earliestCreated) : null;
        $exportData['export_range']['to'] = $latestActivity ? date('c', $latestActivity) : null;

        $exportData['statistics']['total_messages'] = $totalMessages;
        $exportData['statistics']['total_files'] = $totalFiles;
        if ($result['total'] > 0) {
            $exportData['statistics']['avg_messages_per_session'] = round($totalMessages / $result['total'], 1);
        }

        // Add mode counts (computed from the actually exported sessions, respecting filters)
        $exportData['statistics']['ai_sessions'] = $modeCounts['ai'];
        $exportData['statistics']['human_sessions'] = $modeCounts['human'];
        $exportData['statistics']['waiting_sessions'] = $modeCounts['waiting'];
        $exportData['statistics']['internal_sessions'] = $modeCounts['internal'];

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

        $totalMessages = 0;
        foreach ($result['sessions'] as $session) {
            $totalMessages += count($this->getSessionMessages($session));
        }

        $sheet->setCellValue('A10', 'Total Sessions:');
        $sheet->setCellValue('B10', $result['total']);
        $sheet->setCellValue('A11', 'Total Messages:');
        $sheet->setCellValue('B11', $totalMessages);
        $sheet->setCellValue('A12', 'AI Sessions:');
        $sheet->setCellValue('B12', $modeStats['ai']);
        $sheet->setCellValue('A13', 'Human Sessions:');
        $sheet->setCellValue('B13', $modeStats['human']);
        $sheet->setCellValue('A14', 'Waiting Sessions:');
        $sheet->setCellValue('B14', $modeStats['waiting']);

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(40);
    }

    private function createConversationsSheet($sheet, Widget $widget, array $filters): void
    {
        $customFields = $widget->getConfig()['customFields'] ?? [];

        // Header
        $headers = ['Session', 'Channel', 'Time', 'From', 'Language', 'Message'];
        foreach ($customFields as $field) {
            $headers[] = $field['name'];
        }

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col.'1', $header);
            $sheet->getStyle($col.'1')->getFont()->setBold(true);
            $sheet->getStyle($col.'1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E2E8F0');
            ++$col;
        }

        $lastCol = chr(ord('F') + count($customFields));

        // Get sessions and messages
        $result = $this->sessionRepository->findSessionsByWidget($widget->getWidgetId(), 500, 0, $filters);

        $row = 2;
        $sessionNum = 1;
        $lastSessionId = null;

        foreach ($result['sessions'] as $session) {
            $messages = $this->getSessionMessages($session);
            $cfValues = $session->getCustomFieldValues() ?? [];
            $isFirstMessageInSession = true;

            foreach ($messages as $message) {
                // Add separator between sessions
                if (null !== $lastSessionId && $lastSessionId !== $session->getSessionId()) {
                    foreach (range('A', $lastCol) as $sepCol) {
                        $sheet->setCellValue($sepCol.$row, '---');
                    }
                    $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('999999'));
                    ++$row;
                    ++$sessionNum;
                    $isFirstMessageInSession = true;
                }

                $sheet->setCellValue('A'.$row, '#'.$sessionNum);
                $sheet->setCellValue('B'.$row, 'Chat Widget');
                $sheet->setCellValue('C'.$row, date('H:i:s', $message['timestamp']));
                $sheet->setCellValue('D'.$row, $message['sender']);
                $sheet->setCellValue('E'.$row, $message['language']);
                $sheet->setCellValue('F'.$row, $message['text']);

                // Show custom field values on every message row
                if (!empty($customFields)) {
                    $cfCol = chr(ord('F') + 1);
                    foreach ($customFields as $field) {
                        $val = $cfValues[$field['id']] ?? ('boolean' === $field['type'] ? false : '');
                        $displayVal = is_bool($val) ? ($val ? 'Yes' : 'No') : $this->sanitizeCellValue((string) $val);
                        $sheet->setCellValue($cfCol.$row, $displayVal);
                        ++$cfCol;
                    }
                }
                $isFirstMessageInSession = false;

                // Color coding for sender
                if ('IN' === $message['direction']) {
                    $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F0FDF4');
                }

                $lastSessionId = $session->getSessionId();
                ++$row;
            }
        }

        // Auto-size columns
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(80);
        // Auto-size custom field columns
        $cfCol = chr(ord('F') + 1);
        foreach ($customFields as $field) {
            $sheet->getColumnDimension($cfCol)->setAutoSize(true);
            ++$cfCol;
        }

        // Wrap text in message column
        $sheet->getStyle('F:F')->getAlignment()->setWrapText(true);
    }

    private function createSessionsSheet($sheet, Widget $widget, array $filters): void
    {
        // Header
        $headers = ['Session ID', 'Channel', 'Created', 'Last Activity', 'Messages', 'Files', 'Mode', 'Duration'];

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
            $sheet->setCellValue('B'.$row, 'Chat Widget');
            $sheet->setCellValue('C'.$row, date('Y-m-d H:i', $session->getCreated()));
            $sheet->setCellValue('D'.$row, date('Y-m-d H:i', $session->getLastMessage()));
            $sheet->setCellValue('E'.$row, $messageCount);
            $sheet->setCellValue('F'.$row, $fileCount);
            $sheet->setCellValue('G'.$row, ucfirst($session->getMode()));
            $sheet->setCellValue('H'.$row, $durationStr);

            ++$row;
        }

        // Auto-size columns
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Get messages for a session.
     *
     * @return array<array{direction: string, text: string, timestamp: int, sender: string, language: string, files: array}>
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
            $providerIndex = $message->getProviderIndex();
            if ('IN' === $message->getDirection()) {
                $sender = 'Visitor';
            } elseif ('SYSTEM' === $providerIndex) {
                $sender = 'System';
            } elseif ('HUMAN_OPERATOR' === $providerIndex) {
                $sender = 'Support Agent';
            } else {
                $sender = 'AI Assistant';
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
                'text' => $this->sanitizeCellValue($message->getText()),
                'timestamp' => $message->getUnixTimestamp(),
                'sender' => $sender,
                'language' => strtoupper($message->getLanguage()),
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

    /**
     * Prevent CSV/formula injection by escaping leading characters
     * that spreadsheet applications interpret as formulas.
     */
    private function sanitizeCellValue(string $value): string
    {
        if ('' !== $value && str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            || str_starts_with($value, "\t")
            || str_starts_with($value, "\r")
        ) {
            return "'".$value;
        }

        return $value;
    }
}
