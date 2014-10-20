<?php
/**
 * Created by PhpStorm.
 * User: sokrat
 * Date: 20.10.14
 * Time: 18:40
 */

namespace consultnn\minify;


use yii\console\controllers\AssetController;

class Minify
{
    public static function js(AssetController $manager, $inputFiles, $outputFile)
    {
//        var_dump($inputFiles);
//        var_dump($outputFile);
        $tmpFile = $outputFile . '.tmp';
        $manager->combineJsFiles($inputFiles, $tmpFile);
        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);
        var_dump(\JSMin::minify($content));
        exit;
        echo shell_exec(strtr($manager->jsCompressor, [
                    '{from}' => escapeshellarg($tmpFile),
                    '{to}' => escapeshellarg($outputFile),
                ]));

    }

    public static function css(AssetController $manager, $inputFiles, $outputFile)
    {
        var_dump($inputFiles);
        var_dump($outputFile);
        exit;
        $tmpFile = $outputFile . '.tmp';
        $manager->combineCssFiles($inputFiles, $tmpFile);
        echo shell_exec(strtr($manager->cssCompressor, [
                    '{from}' => escapeshellarg($tmpFile),
                    '{to}' => escapeshellarg($outputFile),
                ]));
        @unlink($tmpFile);
    }
}
