<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$api=rtrim($root,"/\\").DIRECTORY_SEPARATOR.'api';
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($api,FilesystemIterator::SKIP_DOTS));
$changed=0;
foreach($iterator as $file){
    if($file->getExtension()!=='php')continue;
    $path=$file->getPathname();
    $source=file_get_contents($path);
    if(!is_string($source))continue;
    $tokens=token_get_all($source);
    $output='';
    $removed=false;
    foreach($tokens as $token){
        if(is_array($token)&&($token[0]===T_COMMENT||$token[0]===T_DOC_COMMENT)){
            $removed=true;
            $text=$token[1];
            $newlines=substr_count($text,"\n");
            if($newlines>0)$output.=str_repeat("\n",$newlines);
            continue;
        }
        $output.=is_array($token)?$token[1]:$token;
    }
    if($removed&&$output!==$source){
        file_put_contents($path,$output);
        $changed++;
    }
}
fwrite(STDOUT,"PHP comments removed from {$changed} files\n");
