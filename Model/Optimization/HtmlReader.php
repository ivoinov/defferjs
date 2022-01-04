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

namespace Ivoinov\DeferJS\Model\Optimization;

class HtmlReader
{
    public function getAllLinks($data)
    {
        $commentTags = $this->getTagsData($data, '<!--', '-->');
        foreach ($commentTags as $comment) {
            $data = str_replace($comment, '', $data);
        }
        $scriptTags = $this->getTagsData($data, '<script', '</script>');
        $imgTags = $this->getTagsData($data, '<img', '>');
        $linkTags = $this->getTagsData($data, '<link', '>');
        $iframeTags = $this->getTagsData($data, '<iframe', '>');
        $videoTags = $this->getTagsData($data, '<video', '</video>');

        return array(
            'script' => $scriptTags,
            'img' => $imgTags,
            'link' => $linkTags,
            'iframe' => $iframeTags,
            'video' => $videoTags,
        );
    }

    public function getTagsData($data, $startTag, $endTag)
    {
        $dataExists = 0;
        $i = 0;
        $endTagCharLen = strlen($endTag);
        $scriptArray = array();
        while ($dataExists != -1 && $i < 500) {
            $dataExists = strpos($data, $startTag, $dataExists);
            if (!empty($dataExists)) {
                $endTagPointer = strpos($data, $endTag, $dataExists);
                $scriptArray[] = substr($data, $dataExists, $endTagPointer - $dataExists + $endTagCharLen);
                $dataExists = $endTagPointer;
            } else {
                $dataExists = -1;
            }
            $i++;
        }

        return $scriptArray;
    }

    public function parseLink($tag, $link)
    {
        $xmlDoc = new \DOMDocument();
        if (@$xmlDoc->loadHTML($link) === false) {
            return array();
        }
        $tagHtml = $xmlDoc->getElementsByTagName($tag);
        $linkArray = array();
        if (!empty($tagHtml[0])) {
            foreach ($tagHtml[0]->attributes as $attr) {
                $linkArray[$attr->nodeName] = $attr->nodeValue;
            }
        }

        return $linkArray;
    }

    public function parseScript($link)
    {
        $linkArray = '';
        $dataExists = strpos($link, '>');
        if (!empty($dataExists)) {
            $endTagPointer = strpos($link, '</script>', $dataExists);
            $linkArray = substr($link, $dataExists + 1, $endTagPointer - $dataExists - 1);
        }

        return $linkArray;
    }

    public function implodeScriptArray($array)
    {
        $link = '<script ';
        foreach ($array as $key => $value) {
            $link .= $key . '="' . $value . '" ';
        }
        $link .= '></script>';

        return $link;
    }

    public function implodeLinkArray($tag, $array)
    {
        $link = '<' . $tag . ' ';
        foreach ($array as $key => $value) {

            $link .= $key . '="' . $value . '" ';
        }
        $link .= '>';

        return $link;
    }

    public function isExternalLink($url)
    {
        $components = parse_url($url);
        return !empty($components['host']) && strcasecmp($components['host'], $_SERVER['HTTP_HOST']);
    }

    public function checkFileExtension($filename, $extension)
    {
        $filenameParts = \explode('?', $filename);
        $string = $filenameParts[0];
        if (strlen($extension) > strlen($string)) {
            return false;
        }

        return substr_compare($string, $extension, strlen($string) - strlen($extension), strlen($extension)) === 0;
    }

    public function speedupInnerJsCustomize($html)
    {
        if (strpos($html, 'window.onload') !== false) {
            $html = str_replace(array('window.onload = function() {', ' };'), array('', ''), $html);
        }

        return $html;
    }

    public function combineGoogleFonts($fullCssUrl)
    {
        global $fonts_api_links;
        $urlParts = parse_url(str_replace('#038;', '&', $fullCssUrl));
        parse_str($urlParts['query'], $parameters);
        if (!empty($parameters['family'])) {
            $fontParts = explode('|', $parameters['family']);
            foreach ($fontParts as $font) {
                if (!empty($font)) {
                    $fontSplit = explode(':', $font);
                    if (empty($fontSplit[0])) {
                        continue;
                    }
                    if (empty($fonts_api_links[$fontSplit[0]]) || !is_array($fonts_api_links[$fontSplit[0]])) {
                        $fonts_api_links[$fontSplit[0]] = array();
                    }
                    $fonts_api_links[$fontSplit[0]] = !empty($fontSplit[1]) ? array_merge(
                        $fonts_api_links[$fontSplit[0]],
                        explode(',', $fontSplit[1])
                    ) : $fonts_api_links[$fontSplit[0]];
                }
            }

            return true;
        }

        return false;
    }
}
