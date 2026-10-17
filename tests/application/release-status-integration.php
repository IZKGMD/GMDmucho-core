<?php
declare(strict_types=1);

namespace MuchoCore\Release {
    // Release checks must remain useful without a GitHub connection.
    function fopen(mixed ...$args): never { throw new \RuntimeException('Offline fixture'); }
}

namespace {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    require dirname(__DIR__, 2).'/public/admin/pages/release.php';

    use MuchoCore\Release\ReleaseService;
    use MuchoCore\Core\HealthService;
    use MuchoCore\Core\SystemRepository;

    function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
    function checkRelease(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
        echo 'PASS '.$message.PHP_EOL;
    }

    $root=sys_get_temp_dir().'/mc-release-'.bin2hex(random_bytes(6));
    mkdir($root,0700);mkdir($root.'/cache',0700);
    try {
        foreach ([
            ['1.0.9','1.0.84',true,false,'Update available'],
            ['1.0.84','1.0.84',false,false,'You are up to date'],
            ['1.1.0','1.0.84',false,true,'Ahead of stable release'],
            ['1.0.84','1.0.9',false,true,'Ahead of stable release'],
        ] as [$installed,$stable,$upgrade,$ahead,$title]) {
            file_put_contents($root.'/VERSION',$installed);
            file_put_contents($root.'/cache/release-check.json',json_encode([
                'checked_unix'=>time(),
                'latest'=>['tag_name'=>$stable,'name'=>'Fixture <script>alert(1)</script>','html_url'=>'https://github.com/IZKGMD/GMDmucho-core/releases/tag/v'.$stable],
            ],JSON_THROW_ON_ERROR));
            $status=ReleaseService::check($root,$root.'/cache');
            checkRelease($status['update_available']===$upgrade && $status['ahead_of_stable']===$ahead,
                'release ordering '.$installed.' vs '.$stable);
            ob_start();renderReleaseUpdatePage($root,$root.'/cache');$html=(string)ob_get_clean();
            checkRelease(str_contains($html,$title),'operator sees correct release state: '.$title);
            checkRelease(str_contains($html,'Automatic update')===$upgrade,'upgrade command appears only for a newer stable release');
            checkRelease(!str_contains($html,'<script>'),'release metadata is escaped');
        }
        unlink($root.'/cache/release-check.json');
        $status=ReleaseService::check($root,$root.'/cache');
        checkRelease($status['source']==='error' && !$status['update_available'],'offline check does not manufacture an update');
        ob_start();renderReleaseUpdatePage($root,$root.'/cache');$html=(string)ob_get_clean();
        checkRelease(str_contains($html,'Release check unavailable') && !str_contains($html,'You are up to date'),
            'offline check does not claim the installation is current');

        $db=new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE server_settings (setting_key TEXT,setting_value TEXT)');
        $db->exec("INSERT INTO server_settings VALUES ('server.version','0.5.0-dev')");
        $health=(new HealthService($db,new SystemRepository($db)))->check();
        checkRelease($health['version']===trim(file_get_contents(dirname(__DIR__,2).'/VERSION')),
            'health reports installed source version rather than the original database seed');
        checkRelease($health['database']==='ok','health still checks the database');
    } finally {
        foreach (glob($root.'/cache/*') ?: [] as $file) unlink($file);
        rmdir($root.'/cache');unlink($root.'/VERSION');rmdir($root);
    }
    echo "MUCHOCORE_RELEASE_STATUS_OK\n";
}
