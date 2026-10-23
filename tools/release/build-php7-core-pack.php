<?php
/** Reproducible code-only pack for the owner/CSRF-protected shared installer. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$source=$root.'/compat/php7';
$destination=isset($argv[1])?$argv[1]:sys_get_temp_dir().'/muchocore-php7-build';
if(!is_dir($destination)&&!mkdir($destination,0700,true))throw new RuntimeException('Output directory unavailable.');
$files=array();
foreach(array('core','vendor') as $directory){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file){
        if(!$file->isFile()||$file->isLink())continue;
        $path=substr($file->getPathname(),strlen($source)+1);
        if(strpos($path,'core/tests/')===0)continue;
        $files[$path]=file_get_contents($file->getPathname());
    }
}
foreach(array('polyfills','schema','data','runtime') as $helper)$files['domain-'.$helper.'.php']=file_get_contents($source.'/domain-'.$helper.'.php');
ksort($files);$entries=array();
foreach($files as $path=>$contents)$entries[]=array('path'=>$path,'sha256'=>hash('sha256',$contents),'data'=>base64_encode($contents));
$bytes=gzencode(json_encode(array('format'=>1,'files'=>$entries),JSON_UNESCAPED_SLASHES),9);
$hash=hash('sha256',$bytes);
file_put_contents($destination.'/php7-core-update.pack',$bytes);
file_put_contents($destination.'/php7-core-update.json',json_encode(array('sha256'=>$hash,'files'=>count($entries),'format'=>1),JSON_PRETTY_PRINT)."\n");
echo 'PHP7_CORE_PACK '.count($entries).' files '.strlen($bytes).' bytes '.$hash."\n";
