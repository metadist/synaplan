<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Entity\Approval;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;

/**
 * "Always allow" on an approval for an MCP tool: sets that tool to Auto on its
 * connection. Only for a write tool on a connection the person owns, and never
 * for a tool that is blocked now. A destructive call is confirmed each time.
 */
final readonly class McpApprovalAllowance
{
    public function __construct(
        private McpServerConfigRepository $servers,
        private McpToolRegistry $registry,
        private McpToolPolicyStore $store,
        private McpToolPermissionView $view,
        private McpToolPermissions $permissions,
    ) {
    }

    public static function isMcpTool(string $toolName): bool
    {
        return 1 === preg_match('/^mcp:\d+:.+$/', $toolName);
    }

    public function grant(Approval $approval, User $actor): bool
    {
        if (SideEffect::Write->value !== $approval->getSideEffect()
            || 1 !== preg_match('/^mcp:(\d+):(.+)$/', $approval->getTool(), $m)) {
            return false;
        }
        $server = $this->servers->findByIdAndUser((int) $m[1], (int) $actor->getId());
        if (null === $server) {
            return false;
        }
        $tool = $m[2];
        $classes = $this->view->classes($this->registry->toolsFor($server));
        $class = $classes[$tool] ?? null;
        if (SideEffect::Write !== $class || PolicyOutcome::Block === $this->permissions->outcome($server, $tool, $class)) {
            return false;
        }
        $this->store->setTools($server, [$tool => PolicyOutcome::Auto], $classes);

        return true;
    }
}
