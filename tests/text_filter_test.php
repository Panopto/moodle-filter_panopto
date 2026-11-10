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
 * Unit tests for the Panopto filter.
 *
 * @package    filter_panopto
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_panopto;

use html_writer;
use moodle_url;

/**
 * Unit tests for the Panopto filter.
 *
 * The Panopto tool of a course is looked up by block_panopto, as the Tiny button does it, and
 * text is formatted with format_text() so that Moodle cleans it between the filter stages.
 *
 * @package    filter_panopto
 * @category   test
 * @copyright  2026 Panopto
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_panopto\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /** @var string Panopto server the test courses use. */
    private const SERVER = 'test.panopto.example';

    /** @var string Resource link id the Tiny button writes. */
    private const RESOURCE_LINK_ID = 'a1b2c3d4_1761750000000';

    /** @var string Custom parameters the Tiny button writes. */
    private const CUSTOM = '{"use_panopto_interactive_view":"false"}';

    /** @var \stdClass Course showing the content. */
    private $course;

    /** @var int Panopto LTI tool of the course. */
    private $toolid;

    /**
     * Set up a course with a Panopto tool and turn the filter on.
     *
     * Skipped when block_panopto is missing, as in CI, which installs this plugin alone: the filter
     * looks up the course Panopto tool through the block.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();

        if (!file_exists($CFG->dirroot . '/blocks/panopto/lib/lti/panoptoblock_lti_utility.php')) {
            $this->markTestSkipped('block_panopto is not installed');
        }

        $this->resetAfterTest();

        require_once($CFG->libdir . '/filterlib.php');

        set_config('automatic_operation_target_server', self::SERVER, 'block_panopto');
        $this->toolid = $this->create_panopto_tool(self::SERVER);
        $this->course = $this->getDataGenerator()->create_course();

        filter_set_global_state('panopto', TEXTFILTER_ON);
        \filter_manager::reset_caches();
    }

    /**
     * Install a Panopto LTI tool for a server, which get_course_tool() picks for courses on it.
     *
     * @param string $server Panopto server name
     * @return int tool type id
     */
    private function create_panopto_tool(string $server): int {
        global $CFG;

        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $toolurl = 'https://' . $server . '/Panopto/LTI/LTI.aspx';

        return (int) lti_add_type(
            (object) [
                'name' => 'Panopto ' . $server,
                'baseurl' => $toolurl,
                'state' => LTI_TOOL_STATE_CONFIGURED,
            ],
            (object) [
                'lti_toolurl' => $toolurl,
                'lti_password' => 'secret-password',
                'lti_resourcekey' => 'resource-key',
                'lti_customparameters' => 'panopto_course_embed_tool=1',
            ]
        );
    }

    /**
     * Build a Panopto iframe as the Tiny button inserts it and Tiny saves it.
     *
     * @param array $params launch parameter overrides, null to leave one out
     * @param string $attributes attributes after src
     * @return string
     */
    private function tiny_iframe(array $params = [], string $attributes = ' width="720" height="405"'): string {
        global $CFG;

        $params = array_filter($params + [
            'course' => $this->course->id,
            'ltitypeid' => $this->toolid,
            'custom' => self::CUSTOM,
            'resourcelinkid' => self::RESOURCE_LINK_ID,
        ], fn($value) => $value !== null);

        $query = [];
        foreach ($params as $name => $value) {
            $query[] = $name . '=' . s($value);
        }

        return '<iframe src="' . $CFG->wwwroot . text_filter::LAUNCH_PATH . '?' . implode('&amp;', $query) . '"'
            . $attributes . ' allowfullscreen="true"></iframe>';
    }

    /**
     * Build a marker link that stands in for a Panopto iframe.
     *
     * @param array $params launch parameter overrides
     * @param string $text link text
     * @param string $class link class
     * @return string
     */
    private function marker(array $params = [], string $text = 'Lecture 1', string $class = text_filter::MARKER_CLASS): string {
        $url = new moodle_url(text_filter::LAUNCH_PATH, $params + [
            'course' => $this->course->id,
            'ltitypeid' => $this->toolid,
            'resourcelinkid' => self::RESOURCE_LINK_ID,
            'custom' => self::CUSTOM,
        ]);

        return html_writer::link($url, $text, ['class' => $class]);
    }

    /**
     * Build the iframe the filter shows.
     *
     * @param array $params launch parameter overrides, null to leave one out
     * @param array $attributes attribute overrides, null to leave one out
     * @return string
     */
    private function expected_iframe(array $params = [], array $attributes = []): string {
        $params = array_filter(array_merge([
            'course' => $this->course->id,
            'ltitypeid' => $this->toolid,
            'resourcelinkid' => self::RESOURCE_LINK_ID,
            'custom' => self::CUSTOM,
            'contenturl' => null,
        ], $params), fn($value) => $value !== null);

        return html_writer::tag('iframe', '', array_merge([
            'src' => new moodle_url(text_filter::LAUNCH_PATH, $params),
            'title' => 'Panopto video',
            'width' => '720',
            'height' => '405',
            'loading' => 'lazy',
            'allowfullscreen' => 'true',
        ], $attributes));
    }

    /**
     * Build the marker link the filter shows for a video it does not embed.
     *
     * @param array $params launch parameter overrides, null to leave one out
     * @param string $title link text
     * @return string
     */
    private function expected_link(array $params = [], string $title = 'Panopto video'): string {
        $params = array_filter(array_merge([
            'course' => $this->course->id,
            'ltitypeid' => $this->toolid,
            'resourcelinkid' => self::RESOURCE_LINK_ID,
            'custom' => self::CUSTOM,
            'contenturl' => null,
            'displaywidth' => '720',
            'displayheight' => '405',
        ], $params), fn($value) => $value !== null);

        return html_writer::link(
            new moodle_url(text_filter::LAUNCH_PATH, $params),
            s($title),
            ['class' => text_filter::MARKER_CLASS]
        );
    }

    /**
     * Format text shown in the test course, cleaning it unless the options say otherwise.
     *
     * @param string $text HTML text
     * @param array $options format_text() options
     * @return string
     */
    private function format(string $text, array $options = []): string {
        return format_text($text, FORMAT_HTML, $options + ['context' => \context_course::instance($this->course->id)]);
    }

    /**
     * Moodle removes Panopto iframes from cleaned text when the filter is off.
     */
    public function test_cleaning_removes_iframes_without_filter(): void {
        filter_set_global_state('panopto', TEXTFILTER_DISABLED);
        \filter_manager::reset_caches();

        $result = $this->format('<p>Watch this</p>' . $this->tiny_iframe());

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('<p>Watch this</p>', $result);
    }

    /**
     * A Panopto iframe saved by the Tiny button is shown after Moodle has cleaned the text.
     */
    public function test_tiny_iframe_is_shown_after_cleaning(): void {
        $result = $this->format('<p>Watch this</p>' . $this->tiny_iframe());

        $this->assertSame('<p>Watch this</p>' . $this->expected_iframe(), $result);
    }

    /**
     * Text that Moodle does not clean keeps its iframes exactly as saved.
     */
    public function test_trusted_text_keeps_its_iframes(): void {
        $iframe = $this->tiny_iframe(['course' => $this->course->id + 1]);

        $this->assertSame($iframe, $this->format($iframe, ['noclean' => true]));
    }

    /**
     * The iframe title is kept, and escaped.
     */
    public function test_iframe_title_is_kept(): void {
        $result = $this->format($this->tiny_iframe([], ' title="Q&amp;A: &lt;intro&gt;" width="720" height="405"'));

        $this->assertSame($this->expected_iframe([], ['title' => 'Q&A: <intro>']), $result);
    }

    /**
     * Each iframe keeps its own launch and size, and the text around them is kept.
     */
    public function test_each_iframe_keeps_its_launch_and_size(): void {
        $first = $this->tiny_iframe(['resourcelinkid' => 'first'], ' width="640" height="360"');
        $second = $this->tiny_iframe(['resourcelinkid' => 'second'], '');

        $result = $this->format($first . '<p>Between</p>' . $second);

        $this->assertSame(
            $this->expected_iframe(['resourcelinkid' => 'first'], ['width' => '640', 'height' => '360'])
                . '<p>Between</p>'
                . $this->expected_iframe(['resourcelinkid' => 'second'], ['width' => null, 'height' => null]),
            $result
        );
    }

    /**
     * Iframes written by hand or by other editors are recognised whatever their case, quoting and
     * attribute order.
     */
    public function test_iframe_markup_variations_are_recognised(): void {
        global $CFG;

        $src = $CFG->wwwroot . text_filter::LAUNCH_PATH . '?course=' . $this->course->id . '&ltitypeid=' . $this->toolid
            . '&resourcelinkid=' . self::RESOURCE_LINK_ID . '&custom=' . rawurlencode(self::CUSTOM);
        $variations = [
            'upper case' => '<IFRAME SRC="' . $src . '" WIDTH="720" HEIGHT="405"></IFRAME>',
            'single quotes' => "<iframe width='720' src='" . $src . "' height='405'></iframe>",
            'unquoted size' => '<iframe height=405 width=720 src="' . $src . '"></iframe>',
            'line breaks' => "<iframe\n  frameborder=\"0\"\n  src=\"" . $src . "\"\n  width=\"720\" height=\"405\">\n</iframe >",
            'fallback content' => '<iframe src="' . $src . '" width="720" height="405"><p>No frames</p></iframe>',
        ];

        foreach ($variations as $name => $iframe) {
            $this->assertSame($this->expected_iframe(), $this->format($iframe), $name);
        }
    }

    /**
     * Videos launch in the course showing them, not the course they were inserted in.
     */
    public function test_launch_uses_course_showing_content(): void {
        $result = $this->format($this->tiny_iframe(['course' => $this->course->id + 100]));

        $this->assertSame($this->expected_iframe(), $result);
    }

    /**
     * Outside a course, videos launch in the site course with its Panopto tool.
     */
    public function test_launch_outside_course_uses_site_course(): void {
        $result = format_text($this->tiny_iframe(), FORMAT_HTML, ['context' => \context_system::instance()]);

        $this->assertStringContainsString(
            ' src="' . (new moodle_url(text_filter::LAUNCH_PATH, ['course' => SITEID, 'ltitypeid' => $this->toolid]))->out(),
            $result
        );
    }

    /**
     * A video saved with a content URL on the course Panopto server keeps it.
     */
    public function test_panopto_content_url_is_kept(): void {
        $contenturl = 'https://' . self::SERVER . '/Panopto/Pages/Viewer.aspx?id=abc';

        $result = $this->format($this->tiny_iframe(['contenturl' => $contenturl]));

        $this->assertSame($this->expected_iframe(['contenturl' => $contenturl]), $result);
    }

    /**
     * Custom data the launch page would ignore is dropped, and the video is still shown.
     */
    public function test_malformed_custom_data_is_dropped(): void {
        foreach (['not json' => 'not-json', 'not an object' => '"text"', 'empty' => ''] as $name => $custom) {
            $result = $this->format($this->tiny_iframe(['custom' => $custom]));

            $this->assertSame($this->expected_iframe(['custom' => null]), $result, $name);
        }
    }

    /**
     * Sizes that are not a number of pixels are dropped, and the video is still shown.
     */
    public function test_invalid_size_is_dropped(): void {
        $result = $this->format($this->tiny_iframe([], ' width="100%" height="99999"'));

        $this->assertSame($this->expected_iframe([], ['width' => null, 'height' => null]), $result);
    }

    /**
     * Iframes that are not a valid Panopto launch on this site are left for Moodle to remove.
     */
    public function test_invalid_iframes_are_removed(): void {
        global $CFG;

        $siteurl = $CFG->wwwroot . text_filter::LAUNCH_PATH;
        $iframes = [
            'other site' => str_replace($CFG->wwwroot, 'https://moodle.example.org', $this->tiny_iframe()),
            'other page' => str_replace($siteurl, $CFG->wwwroot . '/course/view.php', $this->tiny_iframe()),
            'credentials' => str_replace('://', '://user:secret@', $this->tiny_iframe()),
            'script' => str_replace($siteurl, 'javascript:alert(1)//' . $siteurl, $this->tiny_iframe()),
            'missing resource link' => $this->tiny_iframe(['resourcelinkid' => null]),
            'invalid resource link' => $this->tiny_iframe(['resourcelinkid' => 'abc def']),
            'missing tool' => $this->tiny_iframe(['ltitypeid' => null]),
            'invalid tool' => $this->tiny_iframe(['ltitypeid' => 'abc']),
            'array parameter' => str_replace('ltitypeid=', 'ltitypeid[]=', $this->tiny_iframe()),
            'content on other host' => $this->tiny_iframe(['contenturl' => 'https://evil.example/Panopto/Pages/Viewer.aspx']),
            'other iframe' => '<iframe src="https://www.youtube.com/embed/abc"></iframe>',
        ];

        foreach ($iframes as $name => $iframe) {
            $result = $this->format('<p>Before</p>' . $iframe . '<p>After</p>');

            $this->assertSame('<p>Before</p><p>After</p>', $result, $name);
        }
    }

    /**
     * A video for an LTI tool that is not the course Panopto tool is shown as a link, which
     * launches in the course showing it.
     */
    public function test_other_tool_is_shown_as_link(): void {
        $othertoolid = $this->create_panopto_tool('other.panopto.example');

        $result = $this->format($this->tiny_iframe(['course' => $this->course->id + 100, 'ltitypeid' => $othertoolid]));

        $this->assertSame($this->expected_link(['ltitypeid' => $othertoolid]), $result);
    }

    /**
     * A video in a course provisioned to a Panopto server without a tool is shown as a link.
     */
    public function test_course_without_panopto_tool_shows_link(): void {
        global $DB;

        $DB->insert_record('block_panopto_foldermap', [
            'moodleid' => $this->course->id,
            'panopto_id' => '00000000-0000-0000-0000-000000000001',
            'panopto_server' => 'other.panopto.example',
            'panopto_app_key' => 'app-key',
        ]);

        $this->assertSame($this->expected_link(), $this->format($this->tiny_iframe()));
    }

    /**
     * Marker links, as an editor may save them, are shown as iframes with the link text as title.
     */
    public function test_marker_links_are_shown(): void {
        $marker = $this->marker(['displaywidth' => '640', 'displayheight' => '360']);
        $expected = $this->expected_iframe([], ['title' => 'Lecture 1', 'width' => '640', 'height' => '360']);

        $this->assertSame($expected, $this->format($marker));
        $this->assertSame($expected, $this->format($marker, ['noclean' => true]));
    }

    /**
     * Marker links with other classes as well are shown.
     */
    public function test_marker_with_other_classes_is_shown(): void {
        $result = $this->format($this->marker([], 'Lecture 1', 'btn panopto-embed'));

        $this->assertSame($this->expected_iframe([], ['title' => 'Lecture 1', 'width' => null, 'height' => null]), $result);
    }

    /**
     * Links to the launch page without the marker class, such as the thumbnail links the Tiny
     * button inserts, stay links.
     */
    public function test_links_without_marker_class_stay_links(): void {
        $link = $this->marker([], 'Lecture 1', 'thumbnail');

        $this->assertSame($link, $this->format($link));
    }

    /**
     * Videos are shown as links on the assignment Submissions page by default, which launch in
     * the course showing them.
     */
    public function test_submissions_page_shows_links(): void {
        global $PAGE;

        $PAGE->set_pagetype('mod-assign-grading');
        $nosize = ['displaywidth' => null, 'displayheight' => null];

        $this->assertSame(
            $this->expected_link($nosize, 'Lecture 1'),
            $this->format($this->tiny_iframe([], ' title="Lecture 1"'))
        );
        $this->assertSame(
            $this->expected_link($nosize, 'Lecture 2'),
            $this->format($this->marker(['course' => $this->course->id + 100], 'Lecture 2'))
        );
    }

    /**
     * Videos are embedded on the assignment Submissions page when the setting is on.
     */
    public function test_submissions_page_embeds_when_enabled(): void {
        global $PAGE;

        set_config('embedonsubmissionspage', 1, 'filter_panopto');
        $PAGE->set_pagetype('mod-assign-grading');

        $this->assertSame($this->expected_iframe(), $this->format($this->tiny_iframe()));
    }

    /**
     * Strings from format_string() are not filtered.
     */
    public function test_strings_are_not_filtered(): void {
        $filter = new text_filter(\context_course::instance($this->course->id), []);
        $marker = $this->marker();

        $this->assertSame($marker, $filter->filter_stage_string($marker, []));
    }

    /**
     * Text without Panopto videos is returned unchanged.
     */
    public function test_text_without_videos_is_unchanged(): void {
        $filter = new text_filter(\context_course::instance($this->course->id), []);
        $text = '<p>No Panopto video here, just a <a href="https://www.panopto.com">link</a>.</p>';

        $this->assertSame($text, $filter->filter_stage_pre_clean($text, []));
        $this->assertSame($text, $filter->filter_stage_post_clean($text, []));
        $this->assertSame($text, $filter->filter($text));
    }

    /**
     * Forum notification emails show videos as links, which launch in the course showing them.
     * The email renderer filters the text before it cleans it, so iframes would be removed.
     */
    public function test_forum_email_shows_links(): void {
        global $DB, $PAGE;

        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'message' => $this->marker(['course' => $this->course->id + 100], 'Lecture 1')
                . $this->tiny_iframe([], ' title="Lecture 2" width="720" height="405"'),
            'messageformat' => FORMAT_HTML,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id]);

        $renderer = $PAGE->get_renderer('mod_forum', 'email', RENDERER_TARGET_HTMLEMAIL);
        $result = $renderer->format_message_text($cm, $post);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString(
            $this->expected_link(['displaywidth' => null, 'displayheight' => null], 'Lecture 1'),
            $result
        );
        $this->assertStringContainsString($this->expected_link([], 'Lecture 2'), $result);
    }

    /**
     * Videos saved with the default port of their scheme written out match a site without it,
     * while other ports do not.
     */
    public function test_default_port_is_ignored(): void {
        global $CFG;

        $site = parse_url($CFG->wwwroot);
        $defaultport = $site['scheme'] === 'https' ? 443 : 80;
        $withport = fn(int $port) => str_replace(
            $CFG->wwwroot,
            $site['scheme'] . '://' . $site['host'] . ':' . $port . ($site['path'] ?? ''),
            $this->tiny_iframe()
        );

        $this->assertSame($this->expected_iframe(), $this->format($withport($defaultport)));
        $this->assertSame('<p>Before</p>', $this->format('<p>Before</p>' . $withport(8443)));
    }

    /**
     * Videos saved on a site in a subdirectory are recognised after the site moved to https,
     * but not without the subdirectory.
     */
    public function test_site_in_subdirectory_moved_to_https(): void {
        global $CFG;

        $wwwroot = $CFG->wwwroot;
        $CFG->wwwroot = 'https://moodle.example.com/learning';
        try {
            $filter = new text_filter(\context_course::instance($this->course->id), []);
            $saved = str_replace($CFG->wwwroot, 'http://MOODLE.example.com/learning', $this->tiny_iframe());
            $withoutsubdirectory = str_replace($CFG->wwwroot, 'https://moodle.example.com', $this->tiny_iframe());

            $result = $filter->filter_stage_post_clean($filter->filter_stage_pre_clean($saved, []), []);
            $unchanged = $filter->filter_stage_pre_clean($withoutsubdirectory, []);
            $expected = $this->expected_iframe();
        } finally {
            $CFG->wwwroot = $wwwroot;
        }

        $this->assertSame($expected, $result);
        $this->assertStringStartsWith('<iframe src="https://moodle.example.com/learning/lib/editor/tiny/', $result);
        $this->assertSame($withoutsubdirectory, $unchanged);
    }

    /**
     * The Panopto tool of a course is looked up once, however many videos the text has.
     */
    public function test_course_tool_is_looked_up_once(): void {
        global $DB;

        $filter = new text_filter(\context_course::instance($this->course->id), []);
        $markers = $this->marker() . $this->marker(['resourcelinkid' => 'second']);

        $first = $filter->filter_stage_post_clean($markers, []);
        $reads = $DB->perf_get_reads();
        $second = $filter->filter_stage_post_clean($markers, []);

        $this->assertSame(2, substr_count($first, '<iframe '));
        $this->assertSame($first, $second);
        $this->assertSame($reads, $DB->perf_get_reads());
    }
}
