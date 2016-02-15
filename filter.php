<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


defined('MOODLE_INTERNAL') || die('Invalid access');

global $CFG;
require_once $CFG->libdir . '/filelib.php';
require_once $CFG->dirroot . '/repository/elevator/elevatorAPI.php';

class filter_elevator extends moodle_text_filter {

    private $targetString;
    /**
     * Constructor
     * @param object $context
     * @param object $localconfig
     */
    public function __construct($context, array $localconfig) {
        parent::__construct($context, $localconfig);
        $this->targetString = "/placeholderStringForElevatorDoNotRemove/";
    }

    /**
     * Filter the page html and look for an <a><img> element added by the chooser
     * or an <a> element added by the moodle file picker
     *
     * @param string $html
     * @param array $options
     * @return string
     */
    public function filter($html, array $options = array()) {
        global $COURSE;

        $courseid = (isset($COURSE->id)) ? $COURSE->id : null;

        if (empty($html) || !is_string($html) ) {
            return $html;
        }

        if(!(boolean)preg_match($this->targetString, $html)) {
            return $html;
        }

        $dom = new DomDocument();

        // If we're on PHP 5.4.0 or later, we can just ask not to have the doctype and html/body tags included
        // otherwise we need to strip them ourselves
        if(version_compare(phpversion(), '5.4.0') >= 0 && defined(LIBXML_HTML_NOIMPLIED) && defined(LIBXML_HTML_NODEFDTD)) {
            @$dom->loadHtml(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        }
        else {
            @$dom->loadHtml(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            # remove doctype
            $dom->removeChild($dom->doctype);
            # remove <html><body></body></html>
            $dom->replaceChild($dom->firstChild->firstChild->firstChild, $dom->firstChild);
        }

        $xpath = new DOMXPath($dom);
        $matchCount = 0;
        foreach ($xpath->query('//img') as $node) {
            $href = $node->getAttribute('src');

            if (!empty($href) && (boolean)preg_match($this->targetString, $href)) {
                $newnode  = $dom->createDocumentFragment();
                $width = $node->getAttribute('width');
                $height = $node->getAttribute('height');

                $queryString = explode("?", $href);
                if (count($queryString)== 0) {
                    continue;
                }

                parse_str($queryString[1], $parsedHref);

                // we place this string in anything that needs to be replaced.  It is hopefully unlikely to occur randomly.
                if (!array_key_exists("placeholderStringForElevatorDoNotRemove", $parsedHref)) {
                    continue;
                }
                if (!array_key_exists("instance", $parsedHref)) {
                    continue;
                }

                $fileObjectId = $parsedHref["placeholderStringForElevatorDoNotRemove"];
                $instance = $parsedHref["instance"];
                $excerpt = null;
                if (array_key_exists("excerptId", $parsedHref)) {
                    $excerpt = $parsedHref["excerptId"];
                }

                global $CFG;
                $elevatorURL = get_config('elevator', 'elevatorURL');
                $apiKey = get_config('elevator', 'apiKey');
                $apiSecret = get_config('elevator', 'apiSecret');


                $this->elevatorAPI = new elevatorAPI($elevatorURL, $apiKey, $apiSecret);

                // this will return a URL that's designed to be run in an iFrame.
                $embed_url = $this->stripHTTP($this->elevatorAPI->getEmbedContent($fileObjectId, $instance, $excerpt));

                if (empty($width) || empty($height)
                    || ($width == 195 && $height == 110)) {
                    // Keep old moodle embeds @ the default size
                    $width = $this->_default_thumb_width;
                    $height = $this->_default_thumb_height;
                }

                if($matchCount == 0) {
                    $embed_url .= "#firstFrame";
                }
                else {
                    $embed_url .= "#secondFrame";
                }

                $matchCount++;

                $html = '<iframe src="' .$embed_url .'" ' .
                    'width="'. $width . '" ' .
                    'height="' . $height . '" ' .
                    'webkitallowfullscreen="webkitallowfullscreen" ' .
                    'allowfullscreen="allowfullscreen" ' .
                    'frameborder="0"> ' .
                    '</iframe>';

                $newnode->appendXML($html);
                $node->parentNode->replaceChild($newnode, $node);

            }
        }
        return mb_convert_encoding($dom->saveHTML(), "UTF-8", "HTML-ENTITIES");
    }

    function stripHTTP($source) {
        $parsedURL = parse_url($source);
        if(!array_key_exists("scheme", $parsedURL)) {
            return $source;
        }
        return str_ireplace($parsedURL["scheme"] . ":", "", $source);
    }


}
