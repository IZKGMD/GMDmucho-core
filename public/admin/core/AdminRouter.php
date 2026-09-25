<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Router
 * Copyright (C) 2026 IZK
 */

final class MuchoAdminRouter
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function register(
        string $page,
        callable $renderer
    ): self {
        if (
            !preg_match(
                '/^[a-z0-9_-]{1,64}$/',
                $page
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid admin route: '.$page
            );
        }

        $this->routes[$page]=$renderer;

        return $this;
    }


    public function has(
        string $page
    ): bool {
        return isset(
            $this->routes[$page]
        );
    }


    public function dispatch(
        string $page,
        PDO $db
    ): bool {
        if(!$this->has($page)){
            return false;
        }

        ($this->routes[$page])($db);

        return true;
    }


    public function routes(): array
    {
        return array_keys(
            $this->routes
        );
    }
}


function buildMuchoAdminRouter(): MuchoAdminRouter
{
    $router=new MuchoAdminRouter();


    /*
     * Dashboard
     */

    $router->register(
        'dashboard',
        static function(PDO $db): void {

            if(
                !function_exists(
                    'renderAdminDashboard'
                )
            ){
                throw new RuntimeException(
                    'Dashboard module is unavailable.'
                );
            }

            renderAdminDashboard($db);
        }
    );


    /*
     * Core Updates
     */

    $router->register(
        'updates',
        static function(PDO $db): void {
            if (!function_exists('renderReleaseUpdatePage')) {
                throw new RuntimeException(
                    'Release update module is unavailable.'
                );
            }

            $rootDir = defined('ROOT_DIR')
                ? ROOT_DIR
                : dirname(__DIR__, 3);

            $controlDir = defined('CONTROL_DIR')
                ? CONTROL_DIR
                : $rootDir . '/storage/control';

            renderReleaseUpdatePage($rootDir, $controlDir);
        }
    );


    /*
     * Levels
     */

    $router->register(
        'levels',
        static function(PDO $db): void {

            $page='levels';

            require __DIR__.
                '/../pages/levels-moderation.php';
        }
    );


    /*
     * Moderation
     */

    $router->register(
        'moderation',
        static function(PDO $db): void {

            $page='moderation';

            require __DIR__.
                '/../pages/levels-moderation.php';
        }
    );


    /*
     * Rating Studio
     */

    $router->register(
        'rating',
        static function(PDO $db): void {
            require __DIR__.
                '/../pages/rating.php';
        }
    );


    /*
     * Players
     */

    $router->register(
        'players',
        static function(PDO $db): void {

            require __DIR__.
                '/../pages/players.php';
        }
    );


    /*
     * Mucho Profiles
     */

    $router->register(
        'muchoprofiles',
        static function(PDO $db): void {

            require __DIR__.
                '/../pages/muchoprofiles.php';
        }
    );


    /*
     * Client & Features
     */

    $router->register(
        'clientfeatures',
        static function(PDO $db): void {

            if(
                !function_exists(
                    'renderClientFeaturesPage'
                ) ||
                !function_exists(
                    'renderClientReleaseUploader'
                )
            ){
                throw new RuntimeException(
                    'Client Features module is unavailable.'
                );
            }

            renderClientFeaturesPage($db);
            renderClientReleaseUploader($db);
        }
    );


    /*
     * Web Client Patcher
     */

    $router->register(
        'clientpatcher',
        static function(PDO $db): void {
            if (!function_exists('renderClientPatcherPage')) {
                throw new RuntimeException(
                    'Web Client Patcher module is unavailable.'
                );
            }

            renderClientPatcherPage($db);
        }
    );


    /*
     * DB Backup Center
     */

    $router->register(
        'dbbackups',
        static function(PDO $db): void {

            if(
                !function_exists(
                    'renderDbBackupCenter'
                )
            ){
                throw new RuntimeException(
                    'DB Backup Center module is unavailable.'
                );
            }

            renderDbBackupCenter($db);
        }
    );


    /*
     * Security & Monitoring
     */

    $router->register(
        'securitycenter',
        static function(PDO $db): void {

            if(
                !function_exists(
                    'renderSecurityMonitoringPage'
                )
            ){
                throw new RuntimeException(
                    'Security Center module is unavailable.'
                );
            }

            renderSecurityMonitoringPage($db);
        }
    );


    return $router;
}
