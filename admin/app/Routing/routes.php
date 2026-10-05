<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SetupController;

use App\Http\Controllers\Platform\PlatformDashboardController;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ClubSettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\SectionController;

use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequireClubAdmin;

return [

    /**
     * ROOT / AUTH ROUTES
     * ------------------
     * Entry point for the entire system
     */
    'root' => [
        [
            'method' => 'GET',
            'path' => '/',
            'action' => [AuthController::class, 'entry'],
        ],
        [
            'method' => 'POST',
            'path' => 'login',
            'action' => [AuthController::class, 'login'],
        ],
        [
            'method' => 'POST',
            'path' => 'logout',
            'action' => [AuthController::class, 'logout'],
            'middleware' => [
                RequireAuth::class,
            ],
        ],
    ],

    /**
     * PLATFORM ROUTES
     * ---------------
     * Platform Admin control plane
     */
    'platform' => [

        // Platform Dashboard
        [
            'method' => 'GET',
            'path' => '',
            'action' => [PlatformDashboardController::class, 'index'],
            'middleware' => [
                RequirePlatformAdmin::class,
            ],
        ],

        // Initial platform setup / onboarding
        [
            'method' => 'GET',
            'path' => 'setup',
            'action' => [SetupController::class, 'show'],
            'middleware' => [
                RequirePlatformAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'setup',
            'action' => [SetupController::class, 'store'],
            'middleware' => [
                RequirePlatformAdmin::class,
            ],
        ],
    ],

    /**
     * CLUB / USER ROUTES
     * ------------------
     * Context resolved from SESSION, not URL
     */
    'club' => [

        // Main dashboard (club / coach / staff)
        [
            'method' => 'GET',
            'path' => 'dashboard',
            'action' => [DashboardController::class, 'index'],
            'middleware' => [
                RequireAuth::class,
                [RequirePermission::class, 'dashboard.view'],
            ],
        ],

        /**
         * CLUB ADMIN
         */
        [
            'method' => 'GET',
            'path' => 'admin',
            'action' => [AdminDashboardController::class, 'index'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'GET',
            'path' => 'admin/club',
            'action' => [ClubSettingsController::class, 'edit'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/club',
            'action' => [ClubSettingsController::class, 'update'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'GET',
            'path' => 'admin/users',
            'action' => [UserManagementController::class, 'index'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/users/invite',
            'action' => [UserManagementController::class, 'invite'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/users/{user}/role',
            'action' => [UserManagementController::class, 'updateRole'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/users/{user}/status',
            'action' => [UserManagementController::class, 'updateStatus'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'GET',
            'path' => 'admin/sections',
            'action' => [SectionController::class, 'index'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/sections',
            'action' => [SectionController::class, 'store'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/sections/{section}/toggle',
            'action' => [SectionController::class, 'toggle'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
        [
            'method' => 'POST',
            'path' => 'admin/sections/{section}/sort',
            'action' => [SectionController::class, 'sort'],
            'middleware' => [
                RequireAuth::class,
                RequireClubAdmin::class,
            ],
        ],
    ],
];
