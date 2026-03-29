<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ZULU Platform Admin Modules Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines the core navigation structure for both Platform Admin
    | and Company Admin surfaces, following the official 
    | ADMIN_MANAGEMENT_SURFACE_MAP.md architecture.
    |
    */

    'super_admin' => [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'dashboard',
            'route' => 'admin.dashboard',
            'permission' => null,
        ],
        [
            'key' => 'companies',
            'label' => 'Company Management',
            'icon' => 'business',
            'route' => 'admin.companies.index',
            'permission' => 'platform.companies.list',
            'children' => [
                ['label' => 'All Companies', 'route' => 'admin.companies.index', 'icon' => 'list'],
                ['label' => 'Applications', 'route' => 'admin.applications.index', 'icon' => 'assignment_ind'],
                ['label' => 'KYC Documents', 'route' => 'admin.companies.documents', 'icon' => 'folder_shared'],
                ['label' => 'Seller Requests', 'route' => 'admin.companies.seller-requests', 'icon' => 'verified_user'],
            ],
        ],
        [
            'key' => 'users',
            'label' => 'User Management',
            'icon' => 'people',
            'route' => 'admin.users.index',
            'permission' => 'platform.users.list',
            'children' => [
                ['label' => 'B2C Travelers', 'route' => 'admin.users.index', 'icon' => 'person'],
            ],
        ],
        [
            'key' => 'connections',
            'label' => 'Connections',
            'icon' => 'link',
            'route' => 'admin.connections.index',
            'permission' => 'platform.companies.list',
        ],
        [
            'key' => 'inventory',
            'label' => 'Offer Oversight',
            'icon' => 'inventory',
            'route' => 'admin.inventory.flights',
            'permission' => 'platform.inventory.view',
            'children' => [
                ['label' => 'Air Tickets', 'route' => 'admin.inventory.flights', 'icon' => 'flight'],
                ['label' => 'Hotels', 'route' => 'admin.inventory.hotels', 'icon' => 'hotel'],
                ['label' => 'Transfers', 'route' => 'admin.inventory.transfers', 'icon' => 'directions_car'],
                ['label' => 'Excursions', 'route' => 'admin.inventory.excursions', 'icon' => 'explore'],
                ['label' => 'Car Rent', 'route' => 'admin.inventory.cars', 'icon' => 'car_rental'],
                ['label' => 'Visas', 'route' => 'admin.inventory.visas', 'icon' => 'description'],
            ],
        ],
        [
            'key' => 'statistics',
            'label' => 'Statistics',
            'icon' => 'analytics',
            'route' => 'admin.statistics.index',
            'permission' => null,
        ],
        [
            'key' => 'supports',
            'label' => 'Supports',
            'icon' => 'support_agent',
            'route' => 'admin.support.index',
            'permission' => null,
        ],
        [
            'key' => 'sales_bonus',
            'label' => 'Sales and Bonus',
            'icon' => 'workspace_premium',
            'route' => 'admin.sales_bonus',
            'permission' => 'platform.finance.view',
        ],
        [
            'key' => 'packages_platform',
            'label' => 'Packages',
            'icon' => 'inventory_2',
            'route' => 'admin.platform.packages.index',
            'permission' => 'platform.packages.moderate',
            'children' => [
                ['label' => 'All Packages', 'route' => 'admin.platform.packages.index', 'icon' => 'list'],
            ],
        ],
        [
            'key' => 'orders_platform',
            'label' => 'Bookings & Orders',
            'icon' => 'receipt_long',
            'route' => 'admin.platform.package-orders.index',
            'permission' => 'platform.orders.list',
            'children' => [
                ['label' => 'All Orders', 'route' => 'admin.platform.package-orders.index', 'icon' => 'receipt'],
                ['label' => 'Package Orders', 'route' => 'admin.platform.package-orders.index', 'icon' => 'shopping_bag'],
                ['label' => 'Payment Status', 'route' => 'admin.platform.payments.index', 'icon' => 'payments'],
            ],
        ],
        [
            'key' => 'finance_platform',
            'label' => 'Finance & Billing',
            'icon' => 'account_balance',
            'route' => 'admin.platform.finance.invoices',
            'permission' => 'platform.finance.view',
            'children' => [
                ['label' => 'Invoices', 'route' => 'admin.platform.finance.invoices', 'icon' => 'description'],
            ],
        ],
        [
            'key' => 'cms',
            'label' => 'Discovery & CMS',
            'icon' => 'web',
            'route' => 'admin.cms.banners.index',
            'permission' => 'platform.settings.manage',
            'children' => [
                ['label' => 'Banners', 'route' => 'admin.cms.banners.index', 'icon' => 'view_carousel'],
                ['label' => 'Reviews Moderation', 'route' => 'admin.reviews.index', 'icon' => 'star'],
            ],
        ],
        [
            'key' => 'localization',
            'label' => 'Localization',
            'icon' => 'language',
            'route' => 'admin.localization.languages',
            'permission' => 'localization.view',
            'children' => [
                ['label' => 'Languages', 'route' => 'admin.localization.languages', 'icon' => 'flag'],
                ['label' => 'Translations', 'route' => 'admin.localization.translations', 'icon' => 'translate'],
                ['label' => 'Notification Templates', 'route' => 'admin.localization.templates', 'icon' => 'notifications_active'],
            ],
        ],
        [
            'key' => 'system',
            'label' => 'System Settings',
            'icon' => 'settings',
            'route' => 'admin.settings.index',
            'permission' => 'platform.settings.manage',
            'children' => [
                ['label' => 'Global Config', 'route' => 'admin.settings.index', 'icon' => 'tune'],
                ['label' => 'Health Monitor', 'route' => 'admin.settings.health', 'icon' => 'monitor_heart'],
                ['label' => 'Admin Next rollout (R1/R2)', 'route' => 'admin.settings.rollout-observability', 'icon' => 'visibility'],
            ],
        ],
    ],

    'company_admin' => [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'dashboard',
            'route' => 'admin.company.dashboard',
            'permission' => 'companies.view_dashboard',
        ],
        [
            'key' => 'profile',
            'label' => 'My Company',
            'icon' => 'business',
            'route' => 'admin.profile.index',
            'permission' => 'companies.edit_profile',
            'children' => [
                ['label' => 'Company Info', 'route' => 'admin.profile.index', 'icon' => 'info'],
            ],
        ],
        [
            'key' => 'my_offers',
            'label' => 'My Offers',
            'icon' => 'inventory',
            'route' => 'admin.flights.index',
            'permission' => 'platform.inventory.manage',
            'children' => [
                ['label' => 'Air Tickets', 'route' => 'admin.flights.index', 'icon' => 'flight'],
                ['label' => 'Hotels', 'route' => 'admin.hotels.index', 'icon' => 'hotel'],
                ['label' => 'Transfers', 'route' => 'admin.transfers.index', 'icon' => 'directions_car'],
                ['label' => 'Car Rent', 'route' => 'admin.cars.index', 'icon' => 'car_rental', 'zulu_perm' => 'cars.view'],
                ['label' => 'Excursions', 'route' => 'admin.excursions.index', 'icon' => 'hiking', 'zulu_perm' => 'excursions.view'],
            ],
        ],
        [
            'key' => 'statistics',
            'label' => 'Statistics',
            'icon' => 'analytics',
            'route' => 'admin.statistics.index',
            'permission' => null,
        ],
        [
            'key' => 'supports',
            'label' => 'Supports',
            'icon' => 'support_agent',
            'route' => 'admin.support.index',
            'permission' => null,
        ],
        [
            'key' => 'inventory',
            'label' => 'Offer Oversight',
            'icon' => 'inventory',
            'route' => 'admin.inventory.flights',
            'permission' => 'platform.inventory.view',
            'children' => [
                ['label' => 'Air Tickets', 'route' => 'admin.inventory.flights', 'icon' => 'flight'],
                ['label' => 'Hotels', 'route' => 'admin.inventory.hotels', 'icon' => 'hotel'],
                ['label' => 'Transfers', 'route' => 'admin.inventory.transfers', 'icon' => 'directions_car'],
                ['label' => 'Excursions', 'route' => 'admin.inventory.excursions', 'icon' => 'explore'],
                ['label' => 'Car Rent', 'route' => 'admin.inventory.cars', 'icon' => 'car_rental'],
                ['label' => 'Visas', 'route' => 'admin.inventory.visas', 'icon' => 'description'],
            ],
        ],
        [
            'key' => 'sales_bonus',
            'label' => 'Sales and Bonus',
            'icon' => 'workspace_premium',
            'route' => 'admin.sales_bonus',
            'permission' => 'platform.finance.view',
        ],
        [
            'key' => 'packages',
            'label' => 'My Packages',
            'icon' => 'inventory_2',
            'route' => 'admin.packages.index',
            'permission' => 'packages.view',
            'children' => [
                ['label' => 'Package List', 'route' => 'admin.packages.index', 'icon' => 'list'],
                ['label' => 'Package Builder', 'route' => 'admin.packages.index', 'icon' => 'build'],
            ],
        ],
        [
            'key' => 'bookings',
            'label' => 'Bookings & Orders',
            'icon' => 'receipt_long',
            'route' => 'admin.company.bookings.index',
            'permission' => 'bookings.view',
            'children' => [
                ['label' => 'My Bookings', 'route' => 'admin.company.bookings.index', 'icon' => 'book_online'],
                ['label' => 'Package Orders', 'route' => 'admin.company.package-orders.index', 'icon' => 'shopping_bag'],
            ],
        ],
        [
            'key' => 'finance',
            'label' => 'Finance',
            'icon' => 'account_balance_wallet',
            'route' => 'admin.company.finance.invoices',
            'permission' => 'finance.entitlements.view',
            'children' => [
                ['label' => 'Entitlements', 'route' => 'admin.company.finance.entitlements', 'icon' => 'payments'],
                ['label' => 'Settlements', 'route' => 'admin.company.finance.settlements', 'icon' => 'price_check'],
                ['label' => 'Commissions', 'route' => 'admin.company.finance.commissions', 'icon' => 'percent'],
                ['label' => 'Invoices', 'route' => 'admin.company.finance.invoices', 'icon' => 'description'],
            ],
        ],
        [
            'key' => 'team',
            'label' => 'Team / Users',
            'icon' => 'groups',
            'route' => 'admin.company.users.index',
            'permission' => 'company.users.manage',
        ],
        [
            'key' => 'notifications',
            'label' => 'Notifications',
            'icon' => 'notifications',
            'route' => 'admin.notifications.index',
            'permission' => null,
        ],
    ],

];
