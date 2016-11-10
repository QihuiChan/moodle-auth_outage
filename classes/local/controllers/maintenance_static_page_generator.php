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

/**
 * maintenance_static_page_generator class.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_outage\local\controllers;

use coding_exception;
use DOMDocument;
use DOMElement;
use invalid_state_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * maintenance_static_page_generator class.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class maintenance_static_page_generator {
    /** @var DOMDocument */
    protected $dom;

    /** @var maintenance_static_page_io */
    protected $io;

    /**
     * maintenance_static_page_generator constructor.
     * @param DOMDocument|null $dom
     * @param maintenance_static_page_io $io
     * @throws coding_exception
     */
    public function __construct($dom, maintenance_static_page_io $io) {
        if (!is_null($dom) && !($dom instanceof DOMDocument)) {
            throw new coding_exception('$dom must be null or an DOMDocument object.');
        }
        $this->dom = $dom;
        $this->io = $io;
    }

    /**
     * Generates the page.
     */
    public function generate() {
        $this->io->cleanup();

        if (!is_null($this->dom)) {
            $this->io->create_resources_path();

            $this->remove_script_tags();
            $this->update_link_stylesheet();
            $this->update_link_favicon();
            $this->update_images();

            $html = $this->dom->saveHTML();
            if (trim($html) == '') {
                // Should never happen, but just in case...
                throw new invalid_state_exception('Sanity check failed, $html is empty.');
            }

            $this->io->save_template_file($html);
        }
    }

    /**
     * @return maintenance_static_page_io
     */
    public function get_io() {
        return $this->io;
    }



    /**
     * Remove script tags from DOM.
     */
    private function remove_script_tags() {
        $scripts = $this->dom->getElementsByTagName('script');
        // List items to remove without changing the DOM.
        $remove = [];
        foreach ($scripts as $node) {
            $remove[] = $node;
        }
        // All listed, now remove them.
        foreach ($remove as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * Fetch and fixes all link rel="stylesheet" tags.
     */
    private function update_link_stylesheet() {
        $links = $this->dom->getElementsByTagName('link');

        /** @var DOMElement $link */
        foreach ($links as $link) {
            $rel = $link->getAttribute("rel");
            $href = $link->getAttribute("href");
            if (($rel != 'stylesheet') || ($href == '')) {
                continue;
            }
            $saved = $this->io->save_url_file($href);
            if (is_null($saved['url'])) {
                $url = $href; // Skipped, use original URL.
            } else {
                $this->update_link_stylesheet_parse($saved['file'], dirname($href));
                $url = $this->io->get_url_for_file($saved['url']);
            }
            $link->setAttribute('href', $url);
        }
    }

    /**
     * Checks for urls inside filename.
     * @param string $filename
     */
    private function update_link_stylesheet_parse($filename, $baseref) {
        global $CFG;

        $contents = file_get_contents($filename);
        if (!preg_match_all('#url\([\'"]?([^\'"\)]+)#', $contents, $matches)) {
            return;
        }
        foreach ($matches[1] as $original_url) {
            // Allow incomplete URLs in CSS, assume it is from moodle root.
            if (maintenance_static_page_io::is_url($original_url)) {
                $full_url = $original_url;
            } else if ($original_url[0] == '/') {
                $full_url = $CFG->wwwroot.$original_url;
            } else {
                $full_url = $baseref.'/'.$original_url;
            }

            $saved = $this->io->save_url_file($full_url);
            if (!is_null($saved)) {
                $final_url = $this->io->get_url_for_file($saved['url']);
                $contents = str_replace($original_url, $final_url, $contents);
            }
        }

        file_put_contents($filename, $contents);
    }

    /**
     * Fetch and fixes the favicon link tag.
     */
    private function update_link_favicon() {
        $links = $this->dom->getElementsByTagName('link');

        /** @var DOMElement $link */
        foreach ($links as $link) {
            $rel = $link->getAttribute("rel");
            $href = $link->getAttribute("href");
            if (($rel != 'shortcut icon') || ($href == '')) {
                continue;
            }
            $link->setAttribute('href', $this->io->generate_file_url($href)); // Works for most image formats.
        }
    }

    /**
     * Fetch and fixes all img tags.
     */
    private function update_images() {
        $links = $this->dom->getElementsByTagName('img');

        /** @var DOMElement $link */
        foreach ($links as $link) {
            $src = $link->getAttribute("src");
            if ($src == '') {
                continue;
            }
            $link->setAttribute('src', $this->io->generate_file_url($src)); // Works for most image formats.
        }
    }
}
