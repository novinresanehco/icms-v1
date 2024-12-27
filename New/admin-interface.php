<?php
namespace App\Core\Admin;

class AdminPanelManager implements AdminPanelInterface
{
    private SecurityManager $security;
    private MenuBuilder $menuBuilder;
    private DashboardBuilder $dashboardBuilder;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function buildDashboard(SecurityContext $context): Dashboard
    {
        return $this->security->executeCriticalOperation(
            new BuildDashboardOperation(
                $this->dashboardBuilder,
                $this->audit
            ),
            $context
        );
    }

    public function buildMenu(SecurityContext $context): AdminMenu
    {
        return $this->security->executeCriticalOperation(
            new BuildMenuOperation(
                $this->menuBuilder,
                $this->audit
            ),
            $context
        );
    }
}

class BuildDashboardOperation extends CriticalOperation
{
    private DashboardBuilder $builder;
    private AuditLogger $audit;

    public function execute(): Dashboard
    {
        // Build dashboard components
        $dashboard = $this->builder
            ->addStatisticsWidget()
            ->addRecentContentWidget()
            ->addActivityLogWidget()
            ->addQuickActionsWidget()
            ->build();

        // Log access
        $this->audit->logDashboardAccess();

        return $dashboard;
    }

    public function getRequiredPermissions(): array
    {
        return ['admin.dashboard'];
    }
}

class BuildMenuOperation extends CriticalOperation
{
    private MenuBuilder $builder;
    private AuditLogger $audit;

    public function execute(): AdminMenu
    {
        // Build admin menu
        $menu = $this->builder
            ->addDashboardItem()
            ->addContentSection()
            ->addMediaSection()
            ->addUsersSection()
            ->addSettingsSection()
            ->build();

        // Log menu build
        $this->audit->logMenuBuild();

        return $menu;
    }

    public function getRequiredPermissions(): array
    {
        return ['admin.access'];
    }
}

class DashboardBuilder
{
    private SecurityManager $security;
    private array $widgets = [];

    public function addStatisticsWidget(): self
    {
        $this->widgets[] = new StatisticsWidget(
            $this->fetchSecureStats()
        );
        return $this;
    }

    public function addRecentContentWidget(): self
    {
        $this->widgets[] = new RecentContentWidget(
            $this->fetchSecureContent()
        );
        return $this;
    }

    public function addActivityLogWidget(): self
    {
        $this->widgets[] = new ActivityLogWidget(
            $this->fetchSecureActivityLog()
        );
        return $this;
    }

    public function addQuickActionsWidget(): self
    {
        $this->widgets[] = new QuickActionsWidget(
            $this->getSecureQuickActions()
        );
        return $this;
    }

    public function build(): Dashboard
    {
        return new Dashboard($this->widgets);
    }

    private function fetchSecureStats(): array
    {
        // Implementation with security checks
        return [];
    }

    private function fetchSecureContent(): array
    {
        // Implementation with security checks
        return [];
    }

    private function fetchSecureActivityLog(): array
    {
        // Implementation with security checks
        return [];
    }

    private function getSecureQuickActions(): array
    {
        // Implementation with security checks
        return [];
    }
}

class MenuBuilder
{
    private SecurityManager $security;
    private array $items = [];

    public function addDashboardItem(): self
    {
        if ($this->security->hasPermission('admin.dashboard')) {
            $this->items[] = new MenuItem([
                'title' => 'Dashboard',
                'route' => 'admin.dashboard',
                'icon' => 'dashboard'
            ]);
        }
        return $this;
    }

    public function addContentSection(): self
    {
        if ($this->security->hasPermission('content.manage')) {
            $this->items[] = new MenuSection([
                'title' => 'Content',
                'icon' => 'content',
                'items' => [
                    [
                        'title' => 'All Content',
                        'route' => 'admin.content.index'
                    ],
                    [
                        'title' => 'Add New',
                        'route' => 'admin.content.create'
                    ],
                    [
                        'title' => 'Categories',
                        'route' => 'admin.categories.index'
                    ],
                    [
                        'title' => 'Tags',
                        'route' => 'admin.tags.index'
                    ]
                ]
            ]);
        }
        return $this;
    }

    public function addMediaSection(): self
    {
        if ($this->security->hasPermission('media.manage')) {
            $this->items[] = new MenuSection([
                'title' => 'Media',
                'icon' => 'media',
                'items' => [
                    [
                        'title' => 'Media Library',
                        'route' => 'admin.media.index'
                    ],
                    [
                        'title' => 'Upload',
                        'route' => 'admin.media.create'
                    ]
                ]
            ]);
        }
        return $this;
    }

    public function addUsersSection(): self
    {
        if ($this->security->hasPermission('users.manage')) {
            $this->items[] = new MenuSection([
                'title' => 'Users',
                'icon' => 'users',
                'items' => [
                    [
                        'title' => 'All Users',
                        'route' => 'admin.users.index'
                    ],
                    [
                        'title' => 'Add New',
                        'route' => 'admin.users.create'
                    ],
                    [
                        'title' => 'Roles',
                        'route' => 'admin.roles.index'
                    ]
                ]
            ]);
        }
        return $this;
    }

    public