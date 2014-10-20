<?php
/**
 * Created by PhpStorm.
 * User: sokrat
 * Date: 20.10.14
 * Time: 18:40
 */

namespace consultnn\minify;


use yii\base\Exception;
use yii\console\controllers\AssetController;

class Minify
{
    public static function js(AssetController $manager, $inputFiles, $outputFile)
    {
        $tmpFile = $outputFile . '.tmp';
        $manager->combineJsFiles($inputFiles, $tmpFile);
        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);
        $content = ClosureCompiler::minify($content);
        if (!file_put_contents($outputFile, $content)) {
            throw new Exception("Unable to write output JavaScript file '{$outputFile}'.");
        }
    }

    public static function css(AssetController $manager, $inputFiles, $outputFile)
    {
        $tmpFile = $outputFile . '.tmp';
        $manager->combineJsFiles($inputFiles, $tmpFile);
        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);
        $compressor = new Css();
        $content = $compressor->run($content);
        if (!file_put_contents($outputFile, $content)) {
            throw new Exception("Unable to write output Css file '{$outputFile}'.");
        }
    }
}