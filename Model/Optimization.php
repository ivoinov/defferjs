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

namespace Ivoinov\DeferJS\Model;

use Ivoinov\DeferJS\Model\Optimization\HtmlReader;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Ivoinov\DeferJS\Model\Optimization\Cache\FileStorage;
use Magento\Framework\App\Action\Context;

class Optimization
{
    /**
     * @var string[]
     */
    private $innerJsToLazyLoad = [
        'hotjar2',
        'bat.bing',
        'require.config',
        'require(',
        'livechat_visitor_data',
        'richSnippet',
        'GTM',
        'connect.facebook.net',
        'ga',
        'gtag',
        'requirejs.config',
    ];
    /**
     * @var string[]
     */
    private $excludeJs = [
        'widget.js',
        'googletagmanager',
    ];
    /**
     * @var string[]
     */
    private $excludeImages = [
        'outbaxlogo.png',
        'base64',
        'logo',
        'rev-slidebg',
        'no-lazy',
        'facebook',
        'googletagmanager',
    ];
    /**
     * @var array
     */
    private $replaceString = [];
    /**
     * @var array
     */
    private $replaceByString = [];
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var Filesystem
     */
    private $filesystem;
    /**
     * @var HtmlReader
     */
    private $htmlReader;
    /**
     * @var CacheStorageInterface
     */
    private $cacheStorage;
    /**
     * @var DirectoryList
     */
    private $directoryList;
    /**
     * @var Context
     */
    private $appContext;

    public function __construct(
        StoreManagerInterface $storeManager,
        Filesystem $filesystem,
        HtmlReader $htmlReader,
        CacheStorageInterface $cacheStorage,
        DirectoryList $directoryList,
        Context $appContext
    ) {
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
        $this->htmlReader = $htmlReader;
        $this->cacheStorage = $cacheStorage;
        $this->directoryList = $directoryList;
        $this->appContext = $appContext;
    }

    public function getHtml($html)
    {
        $mainCssUrl = [];
        $internalJs = [];
        $lazyLoadJs = [];
        $googleFonts = [];
        $html = str_replace(
            '</body>',
            '<script>if(w3_menuclicked){jQuery(".nav-toggle").click();}</script></body>',
            $html
        );
        $allLinks = $this->htmlReader->getAllLinks($html);
        if (!empty($allLinks['script'])) {
            $mergeJsList = [];
            foreach ($allLinks['script'] as $script) {
                $scriptText = '';
                $scriptObject = $this->htmlReader->parseLink('script', $script);
                if (!array_key_exists('src', $scriptObject)) {
                    $scriptText = $this->htmlReader->parseScript($script);
                }
                if (!empty($scriptObject['type']) &&
                    strtolower($scriptObject['type']) != 'text/javascript' &&
                    strtolower($scriptObject['type']) != 'text/jsx;harmony=true') {
                    continue;
                }
                if (!empty($scriptObject['src'])) {
                    $urlArray = \parse_url($scriptObject['src']);
                    if ($this->isExcludeJs($script)) {
                        $this->setReplaceString($script, $this->htmlReader->implodeScriptArray($scriptObject));
                        continue;
                    }
                    $cleanTags = preg_grep(
                        "/version/",
                        explode("/", $urlArray['path']),
                        PREG_GREP_INVERT
                    );
                    $urlArray['path'] = implode("/", $cleanTags);
                    if (!$this->htmlReader->isExternalLink($scriptObject['src']) &&
                        $this->htmlReader->checkFileExtension($urlArray['path'], '.js')) {
                        $urlArray['path'] = $this->cacheStorage->saveCache($urlArray['path']);
                        $scriptObject['src'] = $this->storeManager->getStore()->getBaseUrl() . $urlArray['path'];
                    }
                    $val = $scriptObject['src'];
                    if (!empty($val) && !$this->htmlReader->isExternalLink($val) && strpos($script, '.js')) {
                        $mergeJsList[] = $urlArray['path'];
                        $this->setReplaceString($script, '');
                    } else {
                        $includeExternalJs = 0;
                        foreach (['livechat', 'reviews'] as $externalJs) {
                            if (strpos($script, $externalJs) !== false) {
                                $includeExternalJs = 1;
                            }
                        }
                        if ($includeExternalJs) {
                            if (!array_key_exists(md5($script), $internalJs)) {
                                $internalJs[md5($script)] = $scriptObject;
                            }
                        } else {
                            $scriptObject['data-src'] = $scriptObject['src'];
                            unset($scriptObject['src']);
                            $this->setReplaceString($script, $this->htmlReader->implodeScriptArray($scriptObject));
                            continue;
                        }
                        $this->setReplaceString($script, '');
                    }
                } else {
                    $innerJs = $scriptText;
                    $excludeJsFlag = false;
                    if (strpos($innerJs, 'jQuery(') === false && strpos($innerJs, '$(') === false) {
                        $excludeJsFlag = true;
                    }
                    if (strpos($innerJs, '/* <![CDATA[ */') !== false) {
                        $excludeJsFlag = true;
                    }
                    foreach ($this->innerJsToLazyLoad as $js) {
                        if (strpos($innerJs, $js) !== false) {
                            $excludeJsFlag = false;
                            break;
                        }
                    }
                    if (!empty($excludeJsFlag)) {
                        continue;
                    }
                    $scriptText = $this->htmlReader->speedupInnerJsCustomize($scriptText);
                    $scriptModified = '<script type="lazyload"';
                    foreach ($scriptObject as $key => $value) {
                        if ($key != 'type') {
                            $scriptModified .= $key . '="' . $value . '"';
                        }
                    }
                    $scriptModified = $scriptModified . '>' . $scriptText . '</script>';
                    $this->setReplaceString($script, $scriptModified);
                }
            }
            $allJsFilename = md5(implode('-', $mergeJsList)) . 'js';
            if (!empty($allJsFilename)) {
                $cacheAbsolutePath = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA)
                    ->getAbsolutePath();
                $cacheAbsolutePath .= FileStorage::PATH_TO_CACHE_FOLDER . DIRECTORY_SEPARATOR . 'all-js';
                if (!file_exists($cacheAbsolutePath)) {
                    mkdir($cacheAbsolutePath, 0777, true);
                }
                $allJsFilepath = $cacheAbsolutePath . DIRECTORY_SEPARATOR . $allJsFilename;
                if (!file_exists($allJsFilepath)) {
                    $allJs = '';
                    foreach ($mergeJsList as $pathToFile) {
                        if (!empty($pathToFile)) {
                            $allJs .= file_get_contents($this->directoryList->getRoot() . $pathToFile) . ";\n";
                        }
                    }
                    $file = fopen($allJsFilepath, 'w');
                    fwrite($file, $allJs);
                    fclose($file);
                }
            }
        }
        foreach ($allLinks['iframe'] as $iframe) {
            $excludeFlag = false;
            foreach ($this->excludeImages as $excludeImage) {
                if (!empty($ex_img) && strpos($iframe, $excludeImage) !== false) {
                    $excludeFlag = true;
                }
            }
            if ($excludeFlag) {
                continue;
            }
            $img_obj = $this->htmlReader->parseLink('iframe', $iframe);
            if (strpos($img_obj['src'], 'youtu') !== false) {
                preg_match("#([\/|\?|&]vi?[\/|=]|youtu\.be\/|embed\/)([a-zA-Z0-9_-]+)#", $img_obj['src'], $matches);
                if (empty($img_obj['style'])) {
                    $img_obj['style'] = '';
                }
                $img_obj['style'] .= 'background-image:url(https://i.ytimg.com/vi/' . trim(
                        end($matches)
                    ) . '/maxresdefault.jpg)';
            }
            $img_obj['data-src'] = $img_obj['src'];
            $img_obj['src'] = 'about:blank';
            $img_obj['data-class'] = 'LazyLoad';
            $this->setReplaceString($iframe, $this->htmlReader->implodeLinkArray('iframe', $img_obj));
        }
        foreach ($allLinks['video'] as $video) {
            $excludeFlag = false;
            foreach ($this->excludeImages as $excludeImage) {
                if (!empty($excludeImage) && strpos($video, $excludeImage) !== false) {
                    $excludeFlag = true;
                }
            }
            if ($excludeFlag) {
                continue;
            }
            $videoSrc = $this->storeManager->getStore()->getBaseUrl(
                ) . 'pub/media/' . FileStorage::PATH_TO_CACHE_FOLDER . '/blank.mp4';
            $imgNew = str_replace('src=', 'data-class="LazyLoad" src="' . $videoSrc . '" data-src=', $video);
            $this->setReplaceString($video, $imgNew);
        }
        foreach ($allLinks['img'] as $image) {
            $excludeFlag = false;
            foreach ($this->excludeImages as $excludeImage) {
                if (!empty($excludeImage) && strpos($image, $excludeImage) !== false) {
                    $excludeFlag = true;
                }
            }
            if ($excludeFlag) {
                continue;
            }
            $imageNew = $image;
            $blankImageUrl = $this->storeManager->getStore()->getBaseUrl(
                ) . 'pub/media/' . FileStorage::PATH_TO_CACHE_FOLDER . '/blank.png';
            if (strpos($image, 'srcset') !== false) {
                $imageNew = str_replace(
                    ' srcset=',
                    ' srcset="' . $this->storeManager->getStore()->getBaseUrl(
                    ) . 'blank.png 500w, ' . $blankImageUrl . ' 1000w" data-srcset=',
                    $imageNew
                );
            }
            $imageNew = str_replace(
                ' src=',
                ' data-class="LazyLoad" src="' . $blankImageUrl . '" data-src=',
                $imageNew
            );
            $html = str_replace($image, $imageNew, $html);
        }
        $fonts_api_links = array();
        if (!empty($allLinks['link'])) {
            foreach ($allLinks['link'] as $css) {
                $cssObject = $this->htmlReader->parseLink(
                    'link',
                    str_replace(
                        $this->storeManager->getStore()->getBaseUrl(),
                        $this->storeManager->getStore()->getBaseUrl(),
                        $css
                    )
                );
                if (!empty($cssObject['rel'])) {
                    if ($cssObject['rel'] == 'stylesheet') {
                        $originalCss = '';
                        $media = '';
                        if (!empty($cssObject['media']) &&
                            $cssObject['media'] != 'all' &&
                            $cssObject['media'] != 'screen') {
                            $media = $cssObject['media'];
                        }
                        $urlArray = parse_url($cssObject['href']);
                        $cleanTags = preg_grep("/version/", explode("/", $urlArray['path']), PREG_GREP_INVERT);
                        $urlArray['path'] = implode("/", $cleanTags);
                        $cleanTags = preg_grep("/version/", explode("/", $cssObject['href']), PREG_GREP_INVERT);
                        $cssObject['href'] = implode("/", $cleanTags);
                        if (!$this->htmlReader->isExternalLink($cssObject['href'])) {
                            if (!$this->htmlReader->checkFileExtension($cssObject['href'], '.css') &&
                                strpos($cssObject['href'], '.css?') === false) {
                                continue;
                            } else {
                                $originalCss = $this->storeManager->getStore()->getBaseUrl() . $urlArray['path'];
                                $urlArray['path'] = $this->cacheStorage->saveCache($urlArray['path']);
                                $cssObject['href'] = $this->storeManager->getStore()->getBaseUrl() . $urlArray['path'];
                            }
                        }
                        $fullCssUrl = $cssObject['href'];
                        $urlArray = parse_url($fullCssUrl);
                        if ($urlArray['host'] == 'fonts.googleapis.com') {
                            $response = $this->htmlReader->combineGoogleFonts($fullCssUrl);
                            if ($response) {
                                $this->setReplaceString($css, '');
                            }
                            continue;
                        }
                        $includeAsInline = strpos($originalCss, '.css') !== false;
                        if (!empty($fullCssUrl) &&
                            !$this->htmlReader->isExternalLink($fullCssUrl) &&
                            !empty($includeAsInline) &&
                            $this->htmlReader->checkFileExtension($fullCssUrl, '.css')
                        ) {
                            $path = \parse_url($fullCssUrl, PHP_URL_PATH);
                            $filename = $this->directoryList->getRoot() . $path;
                            $inlineCss[$filename]['filename'] = $filename;
                            $inlineCss[$filename]['media'] = $media;
                            $this->setReplaceString($css, '');
                        } elseif (!empty($fullCssUrl) &&
                            !$this->htmlReader->isExternalLink($fullCssUrl) &&
                            $this->htmlReader->checkFileExtension($fullCssUrl, '.css')) {
                            $path = parse_url($fullCssUrl, PHP_URL_PATH);
                            $filename = $this->directoryList->getRoot() . $path;
                            if (file_exists($filename) && filesize($filename) > 0) {
                                $this->setReplaceString($css, '');
                            }
                        } elseif ($this->htmlReader->checkFileExtension($fullCssUrl, '.css') ||
                            strpos($fullCssUrl, '.css?')) {
                            $mainCssUrl[] = $fullCssUrl;
                            $this->setReplaceString($css, '');
                        }
                    }
                }
            }
        }
        $appendOnStyle = 3;
        $startBodyPointer = strpos($html, '<body') ?: strpos($html, '</head');
        $headHtml = substr($html, 0, $startBodyPointer);
        if (strpos($headHtml, '<style') !== false) {
            $appendOnStyle = 1;
        } elseif (strpos($headHtml, '<link') !== false) {
            $appendOnStyle = 2;
        }
        if (!empty($fonts_api_links)) {
            $all_links = '';
            foreach ($fonts_api_links as $key => $links) {
                $all_links .= !empty($links) && is_array($links) ? $key . ':' . implode(',', $links) . '|' : $key . '|';
            }
            $googleFonts[] = "https://fonts.googleapis.com/css?family=" . urlencode(trim($all_links, '|'));
        }
        $html = $this->replaceBulk($html);
        $all_inline_css = '';
        if (!empty($inlineCss)) {
            if (is_array($inlineCss) && count($inlineCss) > 0) {

                foreach ($inlineCss as $inline) {

                    $all_inline_css .= !empty($inline['media']) ? '@media ' . $inline['media'] . '{' . file_get_contents(
                            $inline['filename']
                        ) . '}' : file_get_contents($inline['filename']);
                }
            }
        }
        $html = $this->insertContentHead($html, 'main_inline_css', $appendOnStyle);
        $html = str_replace("main_inline_css", '<style>' . $all_inline_css . '</style>', $html);
        if (isset($allJsFilename)) {
            $main_js_url = $this->storeManager->getStore()->getBaseUrl() .
                'pub/media/' .
                FileStorage::PATH_TO_CACHE_FOLDER . '/all-js/' .
                $allJsFilename;
            $internalJs[] = array('src' => $main_js_url);
        }
        $html = str_replace(
            '</body>',
            '<script>' . $this->lazyLoadImages(
                $mainCssUrl,
                $internalJs,
                $lazyLoadJs,
                $googleFonts
            ) . '</script></body>',
            $html
        );

        return $html;
    }

    private function isExcludeJs($scriptPath)
    {
        foreach ($this->excludeJs as $excludeJsPattern) {
            if (strpos($scriptPath, $excludeJsPattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function setReplaceString($stringToReplace, $stringReplaceBy)
    {
        $this->replaceString[] = $stringToReplace;
        $this->replaceByString[] = $stringReplaceBy;
    }

    private function replaceBulk($html)
    {
        return str_replace($this->replaceString, $this->replaceByString, $html);
    }

    private function insertContentHead($html, $content, $pos)
    {
        if ($pos == 1) {
            $html = preg_replace('/<style/', $content . '<style', $html, 1);
        } elseif ($pos == 2) {
            $html = preg_replace('/<link/', $content . '<link', $html, 1);
        } else {
            $html = preg_replace('/<script/', $content . '<script', $html, 1);
        }

        return $html;
    }

    private function lazyLoadImages($mainCssUrl, $internalJs, $lazyLoadJs, $googleFonts)
    {

        $internalJsDelayLoad = 5000;
        $innerJsDelayLoad = 100;
        $jsDelayLoad = 10000;
        $internalCssDelayLoad = 10000;
        $googleFontsDelayLoad = 200;
        $internalJsFinal = array();
        foreach ($internalJs as $value) {
            $internalJsFinal[] = $value;
        }
        $request = $this->appContext->getRequest();
        if ($request->getFullActionName() === 'catalog_product_view') {
            $internalJsDelayLoad = '100';
        }
        $innerScriptOptimizer = '
	var inner_js_delay_load=' . $innerJsDelayLoad . ';var internal_js_delay_load = ' . $internalJsDelayLoad . ';var js_delay_load = ' . $jsDelayLoad . ';var internal_css_delay_load = ' . $internalCssDelayLoad . ';var google_fonts_delay_load = ' . $googleFontsDelayLoad . ';var lazy_load_js=' .
            \json_encode($lazyLoadJs) . ';var internal_js=' . \json_encode(
                $internalJsFinal
            ) . ';var lazy_load_css=' . \json_encode($mainCssUrl) .
            ';var optimize_images_json=' . \json_encode([]) . ';var googlefont=' . \json_encode($googleFonts) . ';
	var bbb = document.querySelector("body");
bbb.classList.add("wide");
bbb.classList.add("layout-1280");
	var wnw_first_js = false;
		var w3_menuclicked = 0;
var w3_menu = document.getElementsByClassName("nav-toggle")[0];
var w3_html = document.getElementsByTagName("html")[0];
w3_menu.addEventListener("click", function(){
if(!w3_html.classList.contains("w3_js")){
    w3_menuclicked=1;
}
});
		var wnw_int_first_js = false;

        var wnw_first_inner_js = false;

        var wnw_first_css = false;

		var wnw_first_google_css = false;

        var wnw_first = false;

        var wnw_optimize_image = false;

		var mousemoveloadimg = false;
        var page_is_scrolled = false;

		setTimeout(function(){load_googlefont();},google_fonts_delay_load);
	setTimeout(function(){load_intJS_main();},internal_js_delay_load);
			setTimeout(function(){load_extJS();},js_delay_load);

        window.addEventListener("DOMContentLoaded", function(event){
			setTimeout(function(){load_extCss();},internal_css_delay_load);
            lazyloadimages(0);
			if(document.getElementById("main-js") !== null){
				load_all_js();
			}
        });

        window.addEventListener("scroll", function(event){
			js_delay_load=500;
           load_all_js();
		   load_extCss();
		});

		window.addEventListener("mousemove", function(){
			js_delay_load=500;
			load_all_js();
			load_extCss();
		});

		window.addEventListener("touchstart", function(){
			js_delay_load=500;
			load_all_js();
			load_extCss();
		});

		function load_all_js(){
				load_intJS_main();
				setTimeout(function(){load_extJS();},js_delay_load);


			if(mousemoveloadimg == false){

				var top = this.scrollY;

				lazyloadimages(top);

				mousemoveloadimg = true;

			}

		}
		function insertAfter(newNode, referenceNode) {
			referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
		}
		function mutate_event(){
			if (document.createEvent) {
				var evt = document.createEvent("MutationEvents");
				evt.initMutationEvent("DOMContentLoaded", true, true, document, "", "", "", 0);
				document.dispatchEvent(evt);
				var evt1 = document.createEvent("MutationEvents");
				evt1.initMutationEvent("load", true, true, document, "", "", "", 0);
				document.dispatchEvent(evt1);
			};
		}
         function load_innerJS(){
           	if(wnw_first_inner_js == false){
			    wnw_first_inner_js = true;
				var inline_scripts = document.getElementsByTagName("script");
				var script_length = inline_scripts.length;
				//console.log(inline_scripts.length);
				var inline_scripts_lazyload = [];
				for (ii = 0; ii < script_length; ii++) {
					if(inline_scripts[ii].getAttribute("type") !== null && inline_scripts[ii].getAttribute("data-load") != "done" && inline_scripts[ii].getAttribute("data-src") === null && inline_scripts[ii].getAttribute("type") == "lazyload"){
						inline_scripts_lazyload.push(inline_scripts[ii]);
					}
				}
				for (ii = 0; ii < inline_scripts_lazyload.length; ii++) {
					//console.log(inline_scripts_lazyload[ii]);
					if(inline_scripts_lazyload[ii].getAttribute("type") !== null && inline_scripts_lazyload[ii].getAttribute("data-load") != "done" && inline_scripts_lazyload[ii].getAttribute("data-src") === null && inline_scripts_lazyload[ii].getAttribute("type") == "lazyload"){
						var s = document.createElement("script");
						for (var i2 = 0; i2 < inline_scripts_lazyload[ii].attributes.length; i2++) {
							var attrib = inline_scripts_lazyload[ii].attributes[i2];
							s.setAttribute(attrib.name, attrib.value);
						}
						s["type"] = "text/javascript";
                        s.innerHTML = inline_scripts_lazyload[ii].innerHTML;
                        insertAfter(s,inline_scripts_lazyload[ii]);
						inline_scripts_lazyload[ii].setAttribute("data-load","done");

						//console.log(inline_scripts[ii].innerHTML);
						//console.log(ii);
					}
				}
				mutate_event();
            }
		}
        var inner_js_counter = -1;
		var s={};
         function load_extJS() {
			if(wnw_first_js){
				return;
			}
			if(!wnw_int_first_js){
				setTimeout(function(){load_extJS();},1000);
				return;
			}
			wnw_first_js = true;
			var static_script = document.getElementsByTagName("script");
			for (i = 0; i < static_script.length; i++) {
				if(static_script[i].getAttribute("data-src") !== null){
					static_script[i].setAttribute("src",static_script[i].getAttribute("data-src"));
					delete static_script[i].dataset.src;
				}
			}
			mutate_event();


        }
        var internal_js_loaded = false;
        var internal_js_called = false;
        var inner_js_counter1 = -1;
		var s1={};
		function load_intJS_main(){
		    if(internal_js_called){
		        return;
		    }
		    internal_js_called = true;
		    load_intJS();
		}
		 function load_intJS() {
			if(wnw_int_first_js){
				return;
			}
			if(inner_js_counter1+1 < internal_js.length){
                inner_js_counter1++;
                var script = internal_js[inner_js_counter1];
            	if(script["src"] !== undefined){
					s1[inner_js_counter1] = document.createElement("script");
					s1[inner_js_counter1]["type"] = "text/javascript";
					for(var key in script){
						s1[inner_js_counter1].setAttribute(key, script[key]);
					}
					s1[inner_js_counter1].onload=function(){
						mutate_event();
					    load_intJS();
					};
					document.getElementsByTagName("head")[0].appendChild(s1[inner_js_counter1]);

				}else{
					load_intJS();
				}
			}else{
				wnw_int_first_js = true;
				setTimeout(function(){load_innerJS();},inner_js_delay_load);
			}

        }


	function load_googlefont(){
			if(wnw_first_google_css == false && typeof googlefont != undefined && googlefont != null && googlefont.length > 0){
				googlefont.forEach(function(src) {
					var load_css = document.createElement("link");
					load_css.rel = "stylesheet";
					load_css.href = src;
					load_css.type = "text/css";
					var godefer2 = document.getElementsByTagName("link")[0];
					if(godefer2 == undefined){
						document.getElementsByTagName("head")[0].appendChild(load_css);
					}else{
						godefer2.parentNode.insertBefore(load_css, godefer2);
					}
				});
				wnw_first_google_css = true;
			}
		}
    var exclude_lazyload = null;

    var win_width = screen.availWidth;

    function load_extCss(){

        if(wnw_first_css == false && lazy_load_css.length > 0){
			lazyloadimages(0);
			lazyloadiframes(0);
            lazy_load_css.forEach(function(src) {

                var load_css = document.createElement("link");

                load_css.rel = "stylesheet";

                load_css.href = src;

                load_css.type = "text/css";

                var godefer2 = document.getElementsByTagName("link")[0];

				if(godefer2 == undefined){

					document.getElementsByTagName("head")[0].appendChild(load_css);

				}else{

					godefer2.parentNode.insertBefore(load_css, godefer2);

				}

            });

            wnw_first_css = true;

        }

    }





    window.addEventListener("scroll", function(event){

         var top = this.scrollY;

         lazyloadimages(top);

         lazyloadiframes(top);



    });

    setInterval(function(){lazyloadiframes(top);},8000);

    setInterval(function(){lazyloadimages(0);},3000);

    function lazyload_img(imgs,bodyRect,window_height,win_width){

        for (i = 0; i < imgs.length; i++) {



            if(imgs[i].getAttribute("data-class") == "LazyLoad"){

                var elemRect = imgs[i].getBoundingClientRect(),

                offset   = elemRect.top - bodyRect.top;

                if(elemRect.top != 0 && elemRect.top - window_height < 200 ){
					compStyles = window.getComputedStyle(imgs[i]);
					if(compStyles.getPropertyValue("opacity") == 0){
						continue;
					}



                    var src = imgs[i].getAttribute("data-src") ? imgs[i].getAttribute("data-src") : imgs[i].src ;

                    var srcset = imgs[i].getAttribute("data-srcset") ? imgs[i].getAttribute("data-srcset") : "";



                    imgs[i].src = src;

                    if(imgs[i].srcset != null & imgs[i].srcset != ""){

                        imgs[i].srcset = srcset;

                    }

                    delete imgs[i].dataset.class;

                    imgs[i].setAttribute("data-done","Loaded");

                }

            }

        }

    }

    function lazyload_video(imgs,top,window_height,win_width){

        for (i = 0; i < imgs.length; i++) {

            var source = imgs[i].getElementsByTagName("source")[0];

		    if(typeof source != "undefined" && source.getAttribute("data-class") == "LazyLoad"){

                var elemRect = imgs[i].getBoundingClientRect();

        	    if(elemRect.top - window_height < 0 && top > 0){

		            var src = source.getAttribute("data-src") ? source.getAttribute("data-src") : source.src ;

                    var srcset = source.getAttribute("data-srcset") ? source.getAttribute("data-srcset") : "";

                    imgs[i].src = src;

                    if(source.srcset != null & source.srcset != ""){

                        source.srcset = srcset;

                    }

                    delete source.dataset.class;

                    source.setAttribute("data-done","Loaded");

                }

            }

        }

    }

    function lazyloadimages(top){

        var imgs = document.getElementsByTagName("img");

        var ads = document.getElementsByClassName("lazyload-ads");

        var sources = document.getElementsByTagName("video");

        var bodyRect = document.body.getBoundingClientRect();

        var window_height = window.innerHeight;

        var win_width = screen.availWidth;

        lazyload_img(imgs,bodyRect,window_height,win_width);

        lazyload_video(sources,top,window_height,win_width);

    }



    lazyloadimages(0);

    function lazyloadiframes(top){

        var bodyRect = document.body.getBoundingClientRect();

        var window_height = window.innerHeight;

        var win_width = screen.availWidth;

        var iframes = document.getElementsByTagName("iframe");

        lazyload_img(iframes,bodyRect,window_height,win_width);

    }';

        return $innerScriptOptimizer;
    }
}
