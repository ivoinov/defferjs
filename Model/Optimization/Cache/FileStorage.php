<?php
/*
 *  Copyright 2016 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License").
 *  You may not use this file except in compliance with the License.
 *  A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 *  or in the "license" file accompanying this file. This file is distributed
 *  on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 *  express or implied. See the License for the specific language governing
 *  permissions and limitations under the License.
 */

namespace Ivoinov\DeferJS\Model\Optimization\Cache;

use Ivoinov\DeferJS\Model\CacheStorageInterface;
use Ivoinov\DeferJS\Model\Optimization\HtmlReader;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;

class FileStorage implements CacheStorageInterface
{
    const PATH_TO_CACHE_FOLDER = 'cache/deffer';
    private $filesystem;
    private $directoryList;
    private $storeManager;
    private $htmlReader;

    public function __construct(
        Filesystem $filesystem,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        HtmlReader $htmlReader
    ) {
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->htmlReader = $htmlReader;
    }

    public function saveCache($path)
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension == 'js') {
            return $this->saveJsToCache($path);
        } elseif ($extension == 'css') {
            return $this->saveCssToCache($path);
        }

        return '';
    }

    public function saveJsToCache($path)
    {
        $cacheFolderPath = $this->getCacheFolderAbsolutePath() . 'js' . DIRECTORY_SEPARATOR;
        $cacheFilePath = $cacheFolderPath . md5($path) . '.js';
        if (!\file_exists($cacheFilePath) ||
            (\file_exists($cacheFilePath && filemtime($cacheFilePath) < filemtime($cacheFilePath)))) {
            if (!\file_exists($cacheFolderPath)) {
                mkdir($cacheFolderPath, 0777, true);
            }
            if (!\file_exists($this->directoryList->getRoot() .  $path)) {
                return '';
            }
            $html = file_get_contents($this->directoryList->getRoot() .  $path);
            $pathParts = explode('/', $path);
            $count = count($pathParts);
            unset($pathParts[$count - 1]);
            $html = str_replace(
                '$(window).load(et_all_elements_loaded)',
                '$(window).load(et_all_elements_loaded);et_all_elements_loaded()',
                $html
            );
            $html = str_replace(
                'document.addEventListener("DOMContentLoaded",function(){',
                'jQuery(document).ready(function(){',
                $html
            );
            $html = str_replace('sourceMappingURL=', 'sourceMappingURL=' . implode('/', $pathParts), $html . ";\n");
            $file = fopen($cacheFilePath, 'w');
            fwrite($file, $html);
            fclose($file);
        }

        return str_replace($this->directoryList->getRoot(), '', $cacheFilePath);
    }

    private function getCacheFolderAbsolutePath()
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath() .
            self::PATH_TO_CACHE_FOLDER . DIRECTORY_SEPARATOR;
    }

    public function saveCssToCache($path)
    {
        $cacheFolderPath = $this->getCacheFolderAbsolutePath() . 'css' . DIRECTORY_SEPARATOR;
        $cacheFilePath = $cacheFolderPath . md5($path) . '.css';
        if (!\file_exists($cacheFilePath) ||
            (\file_exists($cacheFilePath && filemtime($cacheFilePath) < filemtime($cacheFilePath)))) {
            if (!\file_exists($cacheFolderPath)) {
                mkdir($cacheFolderPath, 0777, true);
            }
            if (!\file_exists($this->directoryList->getRoot() .  $path)) {
                return '';
            }
            $webPath = $this->storeManager->getStore()->getBaseUrl() . $path;
            $cssContent = $this->gzRelativeToAbsolutePath($webPath, file_get_contents($this->directoryList->getRoot() .  $path));
            $minify = $this->compressCss($cssContent);
            $file = fopen($cacheFilePath, 'w');
            fwrite($file, $minify);
            fclose($file);
        }

        return str_replace($this->directoryList->getRoot(), '', $cacheFilePath);
    }

    private function compressCss($minify)
    {
        $minify = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $minify);

        return str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), ' ', $minify);
    }

    private function gzRelativeToAbsolutePath($url, $string)
    {
        $urlArray = parse_url($url);
        $url = $this->storeManager->getStore()->getBaseUrl() . $urlArray['path'];
        $matches = $this->getTagsData($string, 'url(', ')');
        $replaceArray = explode('/', str_replace('\'', '/', $url));
        array_pop($replaceArray);
        $urlParentPath = implode('/', $replaceArray);
        foreach ($matches as $match) {
            if (strpos($match, 'data:') !== false || strpos($match, '#') !== false) {
                continue;
            }
            $orgMatch = $match;
            $match1 = str_replace(
                array('url(', ')', "url('", "')", ')', "'", '"', '&#039;'),
                '',
                html_entity_decode($match)
            );
            $match1 = trim($match1);
            if (strpos($match1, '//') > 7) {

                $match1 = substr($match1, 0, 7) . str_replace('//', '/', substr($match1, 7));
            }
            if (strpos($match, 'fonts.googleapis.com') !== false) {
                $string = $this->htmlReader->combineGoogleFonts($match1) ?
                    str_replace('@import ' . $match . ';', '', $string) :
                    $string;
                continue;
            }
            if ($this->htmlReader->isExternalLink($match1)) {
                continue;
            }
            if (strpos($match1, '.css') !== false && strpos($string, '@import ' . $match) !== false) {
                $newString = $this->gzRelativeToAbsolutePath($urlParentPath . '/' . $match1, file_get_contents($match1));
                $string = str_replace('@import ' . $match . ';', $newString, $string);
                continue;
            }
            if ($match1[0] == '/' || strpos($match1, 'http') !== false) {
                continue;
            }
            $replacement = 'url(' . $urlParentPath . '/' . $match1 . ')';
            $string = str_replace($orgMatch, $replacement, $string);
        }

        return $string;
    }

    private function getTagsData($data, $startTag, $endTag) {
        $dataExists = 0;
        $i = 0;
        $endTagCharLength = strlen($endTag);
        $scriptArray = array();
        while ($dataExists != -1 && $i < 500) {
            $dataExists = strpos($data, $startTag, $dataExists);
            if (!empty($dataExists)) {
                $endTagPointer = strpos($data, $endTag, $dataExists);
                $scriptArray[] = substr($data, $dataExists, $endTagPointer - $dataExists + $endTagCharLength);
                $dataExists = $endTagPointer;
            } else {
                $dataExists = -1;
            }
            $i++;
        }

        return $scriptArray;
    }
}

