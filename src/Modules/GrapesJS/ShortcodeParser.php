<?php

namespace PHPageBuilder\Modules\GrapesJS;

use PHPageBuilder\Repositories\PageRepository;
use Exception;

class ShortcodeParser
{
    /**
     * @var PageRenderer $pageRenderer
     */
    protected $pageRenderer;

    /**
     * @var array $renderedBlocks
     */
    protected $renderedBlocks;

    /**
     * @var array $context
     */
    protected $context = [];

    /**
     * @var array $pages
     */
    protected $pages = [];

    /**
     * @var string $language
     */
    protected $language;

    /**
     * ShortcodeParser constructor.
     *
     * @param PageRenderer $pageRenderer
     */
    public function __construct(PageRenderer $pageRenderer)
    {
        $this->pageRenderer = $pageRenderer;
        $this->renderedBlocks = [];

        $pageRepository = new PageRepository;
        foreach ($pageRepository->getAll(['id']) as $page) {
            $this->pages[$page->getId()] = $page->getRoute();
        }
    }

    /**
     * Set the current language.
     *
     * @param $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Perform the tasks for all shortcodes in the given html string.
     *
     * @param $html
     * @param array $context
     * @return mixed|string
     * @throws Exception
     */
    public function doShortcodes($html, array $context = [])
    {
        $this->context = $context;
        $html = $this->doBlockShortcodes($html);
        $html = $this->doPageShortcodes($html);
        $html = $this->doThemeUrlShortcodes($html);
        $html = $this->doBlocksContainerShortcodes($html);
        return $html;
    }

    /**
     * Render all dynamic blocks defined with shortcodes in the given html string.
     *
     * @param $html
     * @param int $maxDepth                     maximum depth of blocks loaded inside blocks
     * @return string
     * @throws Exception
     */
    protected function doBlockShortcodes($html, $maxDepth = 15)
    {
        if ($maxDepth === 0) {
            throw new Exception("Maximum doBlockShortcodes depth has been reached, "
                . "probably due to a circular shortcode reference in one of the theme blocks.");
        }

        $matches = $this->findMatches('block', $html);
        if (empty($matches)) {
            return $html;
        }

        foreach ($matches as $match) {
            if (! isset($match['attributes']['slug'])) {
                continue;
            }
            $slug = $match['attributes']['slug'];
            $id = $match['attributes']['id'] ?? $slug;
            $blockHtml = $this->pageRenderer->renderBlock($slug, $id, $this->context);

            // recursive call to render shortcodes from inside the html of the newly rendered block
            $blockHtml = $this->doBlockShortcodes($blockHtml, $maxDepth - 1);

            // store rendered block in a structure used for outputting all dynamic blocks to the page builder
            if (phpb_in_editmode()) {
                $this->renderedBlocks[$this->language][$id] = [
                    'html' => $this->doShortcodes($blockHtml, $this->context),
                    'settings' => $this->blocksData[$this->language][$id]['settings'] ?? []
                ];
            }

            // replace shortcode match with the $blockHtml (note: this replaces only the first match)
            $pos = strpos($html, $match['shortcode']);
            if ($pos !== false) {
                $html = substr_replace($html, $blockHtml, $pos, strlen($match['shortcode']));
            }
        }

        return $html;
    }

    /**
     * Replace all page shortcodes for the corresponding absolute page url.
     * @todo this currently replaces the shortcode with page route instead of URL
     *
     * @param $html
     * @return mixed
     */
    protected function doPageShortcodes($html)
    {
        $matches = $this->findMatches('page', $html);

        if (empty($matches)) {
            return $html;
        }

        foreach ($matches as $match) {
            if (! isset($match['attributes']['id'])) {
                continue;
            }
            $pageId = $match['attributes']['id'];

            $route = '';
            if (isset($this->pages[$pageId])) {
                $route = $this->pages[$pageId];
            }
            $html = str_replace($match['shortcode'], $route, $html);
        }

        return $html;
    }

    /**
     * Replace all [theme-url] shortcodes for the absolute URL to the theme's public folder.
     *
     * @param $html
     * @return mixed
     */
    protected function doThemeUrlShortcodes($html)
    {
        $matches = $this->findMatches('theme-url', $html);

        if (empty($matches)) {
            return $html;
        }

        foreach ($matches as $match) {
            $themeUrl = phpb_config('theme.folder_url') . '/' . e(phpb_config('theme.active_theme'));
            $html = str_replace($match['shortcode'], $themeUrl, $html);
        }

        return $html;
    }

    /**
     * Replace all [blocks-container] shortcodes for a <div phpb-blocks-container></div>
     *
     * @param $html
     * @return mixed
     */
    protected function doBlocksContainerShortcodes($html)
    {
        $matches = $this->findMatches('blocks-container', $html);

        if (empty($matches)) {
            return $html;
        }

        foreach ($matches as $match) {
            $replacement = '<div phpb-blocks-container></div>';
            $html = str_replace($match['shortcode'], $replacement, $html);
        }

        return $html;
    }

    /**
     * Return all matches of the given shortcode in the given html string.
     *
     * @param $shortcode
     * @param $html
     * @return array            an array with for each $shortcode occurrence an array of attributes
     */
    protected function findMatches($shortcode, $html)
    {
        // RegEx: https://www.regextester.com/104625
        $regex = '/\[' . $shortcode . '(\s.*?)?\](?:([^\[]+)?\[\/' . $shortcode . '\])?/';
        preg_match_all($regex, $html, $pregMatchAll);
        $fullMatches = $pregMatchAll[0];
        $matchAttributeStrings = $pregMatchAll[1];

        // loop through the attribute strings of each $shortcode instance and add the parsed variants to $matches
        $matches = [];
        foreach ($matchAttributeStrings as $i => $matchAttributeString) {
            $matchAttributeString = trim($matchAttributeString);

            // as long as there are attributes in the attributes string, add them to $attributes
            $attributes = [];
            while (strpos($matchAttributeString, '=') !== false) {
                list($attribute, $remainingString) = explode('=', $matchAttributeString, 2);
                $attribute = trim($attribute);

                // if first char is " and at least two " exist, get attribute value between ""
                if (strpos($remainingString, '"') === 0 && strpos($remainingString, '"', 1) !== false) {
                    list($empty, $value, $remainingString) = explode('"', $remainingString, 3);
                    $attributes[$attribute] = $value;
                } else {
                    // attribute value was not between "", get value until next whitespace or until end of $remainingString
                    if (strpos($remainingString, ' ') !== false) {
                        list($value, $remainingString) = explode(' ', $remainingString, 2);
                        $attributes[$attribute] = $value;
                    } else {
                        $attributes[$attribute] = $remainingString;
                        $remainingString = '';
                    }
                }

                $matchAttributeString = $remainingString;
            }

            $matches[] = [
                'shortcode' => $fullMatches[$i],
                'attributes' => $attributes
            ];
        }

        return $matches;
    }

    /**
     * Reset the structure of all rendered blocks.
     */
    public function resetRenderedBlocks()
    {
        $this->renderedBlocks = [];
    }

    /**
     * Return the array of all blocks rendered while parsing shortcodes.
     *
     * @return array
     */
    public function getRenderedBlocks()
    {
        return $this->renderedBlocks;
    }

}
