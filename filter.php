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

        $matchCount = 0;
        preg_match_all('/<img[^>]+\>/i', $html, $imageTags);
        if(count($imageTags) == 0) {
            continue;
        }

        foreach ($imageTags[0] as $image) {
            if (!empty($image) && (boolean)preg_match($this->targetString, $image)) {
                $imageDoc = new DOMDocument();
                $imageDoc->loadHTML($image);
                $imageNodes = $imageDoc->getElementsByTagName("img");
                // if we don't have an element for some reason, continue to the next image.
                if(!$imageNodes || !$imageNodes->item(0)) {
                    continue;
                }
                $imageNode = $imageNodes->item(0);
                $href = $imageNode->getAttribute("src");
                $width = $imageNode->getAttribute('width');
                $height = $imageNode->getAttribute('height');

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
                $iframe = '<iframe src="' .$embed_url .'" ' .
                    'width="'. $width . '" ' .
                    'height="' . $height . '" ' .
                    'webkitallowfullscreen="webkitallowfullscreen" ' .
                    'allowfullscreen="allowfullscreen" ' .
                    'frameborder="0"> ' .
                    '</iframe>';

                $html = str_replace($image, $iframe, $html);
            }
        }

        return $html;
    }

    function stripHTTP($source) {
        $parsedURL = parse_url($source);
        if(!array_key_exists("scheme", $parsedURL)) {
            return $source;
        }
        return str_ireplace($parsedURL["scheme"] . ":", "", $source);
    }


}
