<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressMode;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Admin → System settings → Outbound traffic. The mode and the extra
 * allowlist live in BCONFIG and take effect without a restart. The proxy
 * itself is `EGRESS_PROXY` in the environment, not a field here.
 */
#[AsTaggedItem(priority: 55)]
final readonly class OutboundTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'outbound';
    }

    public function label(): string
    {
        return 'Outbound traffic';
    }

    public function sections(): array
    {
        return [
            'policy' => new ConfigSection('Outbound traffic', [
                'EGRESS_MODE' => new FieldDefinition(
                    type: 'select',
                    description: 'What happens when a program calls a host that is not on the list. "off": nothing changes. "report": the call still goes out, and the host is recorded so you can allow it. "enforce": the call is refused before it connects, and the person sees that the service could not be reached. Starts as off.',
                    default: EgressMode::Off->value,
                    row: new DatabaseRow(EgressConfig::CONFIG_GROUP, EgressConfig::KEY_MODE),
                    options: [EgressMode::Off->value, EgressMode::Report->value, EgressMode::Enforce->value],
                ),
                'EGRESS_ALLOW' => new FieldDefinition(
                    type: 'text',
                    description: 'Extra hosts this installation may call, one per line or a JSON list. A host matches any port. "api.example.com:443" matches that port only. "*.example.org" matches one name in front of example.org, not a name further down. Leave this empty until report mode shows a host you want to allow.',
                    default: '[]',
                    row: new DatabaseRow(EgressConfig::CONFIG_GROUP, EgressConfig::KEY_ALLOW),
                    placeholder: "*.example.org\napi.example.com:443",
                ),
            ]),
        ];
    }
}
