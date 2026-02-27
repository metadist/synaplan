<?php

declare(strict_types=1);

namespace Plugin\CastingData\Service;

use App\Service\Plugin\PluginContextProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Injects live casting platform data into the chat system prompt.
 *
 * When the plugin is enabled for a user, this provider fetches relevant
 * productions and auditions from the external API and formats them as
 * context that the LLM can use to answer performer questions.
 */
final readonly class CastingContextProvider implements PluginContextProviderInterface
{
    private const MAX_CONTEXT_LENGTH = 4000;

    public function __construct(
        private CastingApiClient $apiClient,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(int $userId, array $classification, array $options): bool
    {
        $isWidgetContext = 'widget' === ($classification['source'] ?? null)
            || 'WIDGET' === ($options['channel'] ?? null);

        if (!$isWidgetContext) {
            return false;
        }

        $config = $this->apiClient->getConfig($userId);

        if (!$config) {
            return false;
        }

        return !empty($config['enabled']) && !empty($config['api_url']) && !empty($config['api_key']);
    }

    public function getContext(int $userId, string $userMessage, array $classification, array $options): string
    {
        if (empty($userMessage)) {
            return '';
        }

        $this->logger->info('CastingContextProvider: Fetching casting data', [
            'user_id' => $userId,
            'message_preview' => substr($userMessage, 0, 80),
        ]);

        $productions = $this->apiClient->searchProductions($userId, $userMessage, 5);
        $auditions = $this->apiClient->searchAuditions($userId, query: $userMessage);

        if (empty($productions) && empty($auditions)) {
            $this->logger->debug('CastingContextProvider: No casting data found', [
                'user_id' => $userId,
            ]);

            return '';
        }

        $context = "\n\n--- BEGIN PLUGIN CONTEXT: Casting Platform (live data) ---\n";

        if (!empty($productions)) {
            $context .= "\n### Productions matching query:\n";
            foreach ($productions as $idx => $production) {
                $context .= $this->formatProduction($idx + 1, $production);
                if (strlen($context) > self::MAX_CONTEXT_LENGTH) {
                    break;
                }
            }
        }

        if (strlen($context) < self::MAX_CONTEXT_LENGTH && !empty($auditions)) {
            $context .= "\n### Active Auditions:\n";
            foreach ($auditions as $idx => $audition) {
                $context .= $this->formatAudition($idx + 1, $audition);
                if (strlen($context) > self::MAX_CONTEXT_LENGTH) {
                    break;
                }
            }
        }

        if (strlen($context) > self::MAX_CONTEXT_LENGTH) {
            $context = substr($context, 0, self::MAX_CONTEXT_LENGTH)."\n[... truncated]\n";
        }

        $context .= "\nUse this data to answer the user's question accurately. If the data doesn't contain relevant information, say so honestly.\n";
        $context .= "--- END PLUGIN CONTEXT ---\n";

        $this->logger->info('CastingContextProvider: Context built', [
            'user_id' => $userId,
            'productions_count' => count($productions),
            'auditions_count' => count($auditions),
            'context_length' => strlen($context),
        ]);

        return $context;
    }

    /**
     * Format a single production for the context block.
     *
     * CastApp response: { id, title, description, category, year, genre, roles: [{ id, title, active }], auditions: [...] }
     */
    private function formatProduction(int $num, array $production): string
    {
        $name = $production['title'] ?? $production['name'] ?? 'Unknown';
        $category = $production['category'] ?? $production['type'] ?? '';
        $line = sprintf("[%d] \"%s\"", $num, $name);

        if (!empty($category)) {
            $line .= ' - '.$category;
        }

        if (!empty($production['year'])) {
            $line .= ' ('.$production['year'].')';
        }

        $line .= "\n";

        // Format roles if present (CastApp: { id, title, active })
        $roles = $production['roles'] ?? $production['role_positions'] ?? [];
        if (!empty($roles)) {
            $roleStrings = [];
            foreach ($roles as $role) {
                $roleName = $role['title'] ?? $role['name'] ?? $role['role_name'] ?? 'Unknown Role';
                $details = [];

                if (!empty($role['voice_type'])) {
                    $details[] = $role['voice_type'];
                }
                if (!empty($role['age_from']) || !empty($role['age_to'])) {
                    $ageFrom = $role['age_from'] ?? '?';
                    $ageTo = $role['age_to'] ?? '?';
                    $details[] = sprintf('age %s-%s', $ageFrom, $ageTo);
                } elseif (!empty($role['age_range'])) {
                    $details[] = 'age '.$role['age_range'];
                }
                if (!empty($role['gender'])) {
                    $details[] = $role['gender'];
                }

                $roleStr = $roleName;
                if (!empty($details)) {
                    $roleStr .= ' ('.implode(', ', $details).')';
                }

                $roleStrings[] = $roleStr;
            }
            $line .= '    Roles: '.implode(', ', $roleStrings)."\n";
        }

        // Add description if available
        $description = $production['description'] ?? $production['synopsis'] ?? '';
        if (!empty($description)) {
            $line .= '    Description: '.substr((string) $description, 0, 200)."\n";
        }

        return $line;
    }

    /**
     * Format a single audition for the context block.
     *
     * CastApp response: { id, production_id, production_title, theatre_name, cities,
     *   deadline, start, end, teaser, description, professions: [...], roles: [...] }
     */
    private function formatAudition(int $num, array $audition): string
    {
        $productionName = $audition['production_title'] ?? $audition['production_name'] ?? $audition['production']['name'] ?? 'Unknown Production';
        $deadline = $audition['deadline'] ?? $audition['deadline_at'] ?? '';

        $line = sprintf("[%d] Audition for \"%s\"", $num, $productionName);

        if (!empty($deadline)) {
            $line .= ' - Deadline: '.$deadline;
        }

        $line .= "\n";

        // Location: CastApp uses theatre_name + cities
        $theatre = $audition['theatre_name'] ?? '';
        $cities = $audition['cities'] ?? '';
        $location = $audition['location'] ?? $audition['venue'] ?? '';

        if (!empty($theatre)) {
            $location = $theatre;
            if (!empty($cities)) {
                $location .= ', '.$cities;
            }
        } elseif (!empty($cities)) {
            $location = $cities;
        }

        if (!empty($location)) {
            $line .= '    Location: '.$location."\n";
        }

        // Date range
        $start = $audition['start'] ?? '';
        $end = $audition['end'] ?? '';
        if (!empty($start) && !empty($end)) {
            $line .= '    Period: '.$start.' to '.$end."\n";
        } elseif (!empty($start)) {
            $line .= '    Start: '.$start."\n";
        }

        // Professions (CastApp: array of strings like ["Singer", "Actor"])
        $professions = $audition['professions'] ?? [];
        if (!empty($professions) && is_array($professions)) {
            $line .= '    Professions: '.implode(', ', $professions)."\n";
        }

        // Roles from production (CastApp: [{ id, title, active }])
        $roles = $audition['roles'] ?? [];
        if (!empty($roles) && is_array($roles)) {
            $roleNames = array_map(fn (array $r) => $r['title'] ?? $r['name'] ?? 'Unknown', $roles);
            $line .= '    Roles: '.implode(', ', $roleNames)."\n";
        }

        // Description / teaser
        $description = $audition['description'] ?? $audition['teaser'] ?? $audition['requirements'] ?? '';
        if (!empty($description)) {
            $line .= '    Details: '.substr((string) $description, 0, 200)."\n";
        }

        // Special flags
        $flags = [];
        if (!empty($audition['not_after_deadline'])) {
            $flags[] = 'strict deadline';
        }
        if (!empty($audition['not_without_sound'])) {
            $flags[] = 'sound sample required';
        }
        if (!empty($audition['not_without_video'])) {
            $flags[] = 'video required';
        }
        if (!empty($audition['junior_audition'])) {
            $flags[] = 'junior audition';
        }
        if (!empty($flags)) {
            $line .= '    Note: '.implode(', ', $flags)."\n";
        }

        return $line;
    }
}
