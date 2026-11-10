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
 * Panopto filter.
 *
 * @package    filter_panopto
 * @copyright  2026 Panopto
 * @author     Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_panopto;

use html_writer;
use moodle_url;

/**
 * Shows Panopto videos inserted with the Panopto Tiny button in content that Moodle cleans.
 *
 * Moodle stores the iframes the Tiny button inserts, but removes them when it cleans the content
 * of users without trusted text permissions for display. Before cleaning, Panopto iframes are
 * replaced by marker links, which Moodle keeps. After cleaning, marker links that launch the
 * Panopto tool of the course are turned back into iframes.
 *
 * @package    filter_panopto
 * @copyright  2026 Panopto
 * @author     Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** @var string Launch page of tiny_panoptoltibutton, relative to wwwroot. */
    public const LAUNCH_PATH = '/lib/editor/tiny/plugins/panoptoltibutton/view.php';

    /** @var string Class of the links that stand in for Panopto iframes. */
    public const MARKER_CLASS = 'panopto-embed';

    /** @var string Matches an iframe up to its closing tag, capturing its attributes. */
    private const IFRAME_PATTERN = '~<iframe\b([^>]*)>(?:(?!<iframe\b).)*?</iframe\s*>~is';

    /** @var string Matches a link, capturing its attributes and content. */
    private const LINK_PATTERN = '~<a\b([^>]*)>(.*?)</a\s*>~is';

    /** @var string Matches an HTML attribute, capturing its name and its quoted or unquoted value. */
    private const ATTRIBUTE_PATTERN = '~([a-z][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i';

    /** @var string Matches a width or height the Tiny button writes, in pixels. */
    private const DIMENSION_PATTERN = '/^[1-9][0-9]{0,3}$/';

    /** @var string[] Iframe attributes and the marker parameters that keep them. */
    private const DIMENSIONS = ['width' => 'displaywidth', 'height' => 'displayheight'];

    /** @var int[] Panopto LTI tool id of each course looked up, 0 for none. */
    private $coursetoolids = [];

    /**
     * Replace Panopto iframes with marker links before Moodle cleans the text and removes them.
     *
     * @param string $text HTML content to process
     * @param array $options options passed to the filters
     * @return string
     */
    public function filter_stage_pre_clean(string $text, array $options): string {
        // Text that is not cleaned keeps its iframes, so leave it as the author saved it.
        if (!empty($options['noclean']) || stripos($text, '<iframe') === false) {
            return $text;
        }

        return preg_replace_callback(self::IFRAME_PATTERN, [$this, 'replace_iframe'], $text) ?? $text;
    }

    /**
     * Leave strings from format_string() unchanged, as they never hold videos.
     *
     * @param string $text text to process
     * @param array $options options passed to the filters
     * @return string
     */
    public function filter_stage_string(string $text, array $options): string {
        return $text;
    }

    /**
     * Turn marker links back into iframes after Moodle has cleaned the text.
     *
     * @param string $text HTML content to process
     * @param array $options options passed to the filters
     * @return string
     */
    public function filter_stage_post_clean(string $text, array $options): string {
        if (stripos($text, self::MARKER_CLASS) === false) {
            return $text;
        }

        return $this->replace_markers($text, $this->show_links());
    }

    /**
     * Filter text outside the format_text() stages, as forum notification emails do before they
     * clean it. Iframes would not survive that cleaning, and mail clients do not show them, so
     * videos are shown as links.
     *
     * @param string $text some HTML content to process
     * @param array $options options passed to the filters
     * @return string the HTML content after the filtering has been applied
     */
    public function filter($text, array $options = []) {
        if (!is_string($text)) {
            return $text;
        }

        $text = $this->filter_stage_pre_clean($text, $options);
        if (stripos($text, self::MARKER_CLASS) === false) {
            return $text;
        }

        return $this->replace_markers($text, true);
    }

    /**
     * Replace the marker links in a text.
     *
     * @param string $text HTML content with marker links
     * @param bool $links whether to show every marker as a link
     * @return string
     */
    private function replace_markers(string $text, bool $links): string {
        return preg_replace_callback(
            self::LINK_PATTERN,
            fn($match) => $this->replace_marker($match, $links),
            $text
        ) ?? $text;
    }

    /**
     * Whether to keep markers as links, which avoids one LTI launch per submission on the
     * assignment Submissions page.
     *
     * @return bool
     */
    private function show_links(): bool {
        global $PAGE;

        return !get_config('filter_panopto', 'embedonsubmissionspage') && $PAGE->pagetype === 'mod-assign-grading';
    }

    /**
     * Replace a Panopto iframe with a marker link, or keep any other iframe.
     *
     * @param array $match match of {@see self::IFRAME_PATTERN}
     * @return string
     */
    private function replace_iframe(array $match): string {
        $attributes = $this->parse_attributes($match[1]);
        $params = $this->parse_launch_url($attributes['src'] ?? '');
        if ($params === null) {
            return $match[0];
        }

        foreach (self::DIMENSIONS as $attribute => $param) {
            unset($params[$param]);
            if (preg_match(self::DIMENSION_PATTERN, $attributes[$attribute] ?? '')) {
                $params[$param] = $attributes[$attribute];
            }
        }

        return $this->marker_link($params, $attributes['title'] ?? '');
    }

    /**
     * Replace a marker link with an iframe if it launches the Panopto tool of the course. Any
     * other valid marker is rebuilt as a link that launches in the course showing it.
     *
     * @param array $match match of {@see self::LINK_PATTERN}
     * @param bool $links whether to show every marker as a link
     * @return string
     */
    private function replace_marker(array $match, bool $links): string {
        $attributes = $this->parse_attributes($match[1]);
        $classes = preg_split('/\s+/', $attributes['class'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $params = in_array(self::MARKER_CLASS, $classes) ? $this->parse_launch_url($attributes['href'] ?? '') : null;
        if ($params === null) {
            return $match[0];
        }

        $title = html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $courseid = $this->get_course_id();
        if ($links || $params['ltitypeid'] !== (string) $this->get_course_tool_id($courseid)) {
            return $this->marker_link($params, $title);
        }

        $iframe = [];
        foreach (self::DIMENSIONS as $attribute => $param) {
            $iframe[$attribute] = $params[$param] ?? null;
            unset($params[$param]);
        }

        return html_writer::tag('iframe', '', [
            'src' => new moodle_url(self::LAUNCH_PATH, ['course' => $courseid] + $params),
            'title' => $this->get_title($title),
            'width' => $iframe['width'],
            'height' => $iframe['height'],
            'loading' => 'lazy',
            'allowfullscreen' => 'true',
        ]);
    }

    /**
     * Build a marker link that launches in the course showing the content.
     *
     * The course stored with the video is the one it was inserted in, which differs once the
     * content is copied, imported or restored into another course.
     *
     * @param array $params launch parameters from {@see self::parse_launch_url()}
     * @param string $title video title
     * @return string
     */
    private function marker_link(array $params, string $title): string {
        return html_writer::link(
            new moodle_url(self::LAUNCH_PATH, ['course' => $this->get_course_id()] + $params),
            s($this->get_title($title)),
            ['class' => self::MARKER_CLASS]
        );
    }

    /**
     * Get the title to show for a video, or the default title if it has none.
     *
     * @param string $title
     * @return string
     */
    private function get_title(string $title): string {
        $title = trim($title);

        return $title !== '' ? $title : get_string('defaulttitle', 'filter_panopto');
    }

    /**
     * Read the attributes of an HTML tag. Names are lower case, values are decoded and the first
     * of repeated attributes is kept, as browsers do.
     *
     * @param string $html the attributes part of the tag
     * @return string[] values by attribute name
     */
    private function parse_attributes(string $html): array {
        preg_match_all(self::ATTRIBUTE_PATTERN, $html, $matches, PREG_SET_ORDER);

        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (!array_key_exists($name, $attributes)) {
                $value = implode('', array_slice($match, 2));
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $attributes;
    }

    /**
     * Read the parameters of a URL that launches a Panopto video through the Tiny button launch
     * page of this site, checked the way that page checks them.
     *
     * The scheme is not compared, so videos saved before a site moved to https still match. The
     * course is left out, as videos launch in the course showing them.
     *
     * @param string $url absolute URL
     * @return array|null launch parameters, or null if the URL is not a valid Panopto launch
     */
    private function parse_launch_url(string $url): ?array {
        global $CFG;

        $parts = parse_url($url);
        $site = parse_url($CFG->wwwroot);
        if (
            empty($parts['host'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || strcasecmp($parts['host'], $site['host']) !== 0
            || $this->get_port($parts) !== $this->get_port($site)
            || ($parts['path'] ?? '') !== ($site['path'] ?? '') . self::LAUNCH_PATH
        ) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $query = array_filter($query, 'is_string');

        $ltitypeid = $query['ltitypeid'] ?? '';
        $resourcelinkid = $query['resourcelinkid'] ?? '';
        if (
            !preg_match('/^[1-9][0-9]*$/', $ltitypeid)
            || $resourcelinkid === ''
            || clean_param($resourcelinkid, PARAM_ALPHANUMEXT) !== $resourcelinkid
        ) {
            return null;
        }

        $params = [
            'ltitypeid' => $ltitypeid,
            'resourcelinkid' => $resourcelinkid,
            'custom' => $query['custom'] ?? '',
            'contenturl' => $query['contenturl'] ?? '',
        ];

        // The launch page refuses content on any host that is not a Panopto server of this site.
        if ($params['contenturl'] !== '' && !$this->is_panopto_content_url($params['contenturl'])) {
            return null;
        }

        // The launch page ignores anything but a JSON object, so drop it rather than the video.
        if (!is_array(json_decode($params['custom'], true))) {
            $params['custom'] = '';
        }

        foreach (self::DIMENSIONS as $param) {
            if (preg_match(self::DIMENSION_PATTERN, $query[$param] ?? '')) {
                $params[$param] = $query[$param];
            }
        }

        return array_filter($params, fn($value) => $value !== '');
    }

    /**
     * Get the port of a parsed URL, or null for the default port of its scheme.
     *
     * @param array $parts URL parts from parse_url()
     * @return int|null
     */
    private function get_port(array $parts): ?int {
        $default = ['http' => 80, 'https' => 443][strtolower($parts['scheme'] ?? '')] ?? null;
        $port = $parts['port'] ?? null;

        return $port === $default ? null : $port;
    }

    /**
     * Whether a content URL is a URL on one of the Panopto servers configured for this site.
     *
     * @param string $url
     * @return bool
     */
    private function is_panopto_content_url(string $url): bool {
        global $CFG;

        require_once($CFG->dirroot . '/blocks/panopto/lib/block_panopto_lib.php');

        return clean_param($url, PARAM_URL) === $url && panopto_is_allowed_panopto_host($url);
    }

    /**
     * Get the course of the context being filtered, or the site course outside a course.
     *
     * @return int
     */
    private function get_course_id(): int {
        $coursecontext = $this->context ? $this->context->get_course_context(false) : false;

        return $coursecontext ? (int) $coursecontext->instanceid : SITEID;
    }

    /**
     * Get the Panopto LTI tool of a course, as the Tiny button chooses it.
     *
     * @param int $courseid
     * @return int the tool id, or 0 if the course has no Panopto tool
     */
    private function get_course_tool_id(int $courseid): int {
        global $CFG;

        if (!array_key_exists($courseid, $this->coursetoolids)) {
            require_once($CFG->dirroot . '/blocks/panopto/lib/lti/panoptoblock_lti_utility.php');

            $tool = \panoptoblock_lti_utility::get_course_tool($courseid);
            $this->coursetoolids[$courseid] = $tool ? (int) $tool->id : 0;
        }

        return $this->coursetoolids[$courseid];
    }
}
