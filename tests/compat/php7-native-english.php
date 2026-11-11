<?php
/** Shared UI and server-generated messages use English; user data is untouched. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$files=array();
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/compat/php7',FilesystemIterator::SKIP_DOTS)) as $file){
    $path=$file->getPathname();
    if($file->getExtension()==='php'&&!preg_match('~/compat/php7/(core|vendor|builds)/~',$path))$files[]=$path;
}
// The 5v.pl first-install wizard is intentionally localized for beginners.
// The native runtime, regular admin shell and API messages remain English.
$files=array_merge($files,array_filter(glob($root.'/public/php7*.php'),function($path){
    return basename($path)!=='php7-setup.php';
}));
$setup=(string)file_get_contents($root.'/public/php7-setup.php');
if(strpos($setup,'<html lang="ru">')===false || strpos($setup,'Открыть')!==false && strpos($setup,'Завершить настройку')===false){
    throw new RuntimeException('Localized 5v.pl first-install wizard is missing its Russian UI.');
}
foreach($files as $file)foreach(token_get_all(file_get_contents($file)) as $token){
    if(is_array($token)&&in_array($token[0],array(T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE,T_INLINE_HTML),true)&&preg_match('/[\x{0400}-\x{04ff}]/u',$token[1]))throw new RuntimeException('Untranslated shared message: '.$file.':'.$token[2]);
}
foreach(glob($root.'/public/assets/php7*.js') as $file)if(preg_match('/[\x{0400}-\x{04ff}]/u',file_get_contents($file)))throw new RuntimeException('Untranslated shared script: '.$file);
if(preg_match('/[\x{0400}-\x{04ff}]/u',file_get_contents($root.'/tools/release/build-php7-native.sh')))throw new RuntimeException('Untranslated shared archive instructions.');
if(strpos(file_get_contents($root.'/compat/php7/admin-view.php'),'lang="en"')===false)throw new RuntimeException('Shared document language must be English.');
echo "PHP7_SHARED_ENGLISH_MESSAGES_OK\n";
