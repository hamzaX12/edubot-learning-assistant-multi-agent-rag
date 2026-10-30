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
 * Hook callbacks for EduBot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for injecting EduBot CSS and HTML.
 */
class hook_callbacks {

    /**
     * Inject EduBot CSS into the <head> tag.
     * Only loads when inside a course page (courseid > 1).
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $PAGE, $COURSE;

        // Check if plugin is enabled.
        if (!get_config('local_edubot', 'plugin_enabled')) {
            return;
        }

        // Check if user is logged in.
        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Only show chatbot inside course pages.
        if (!$COURSE || $COURSE->id <= 1) {
            return;
        }

        // Do NOT inject chatbot CSS during an active quiz attempt.
        // The chatbot should only appear after submission (mod-quiz-review).
        $pagetype = $PAGE->pagetype ?? '';
        if (strpos($pagetype, 'mod-quiz-attempt') !== false ||
            strpos($pagetype, 'mod-quiz-view') !== false) {
            return;
        }

        $PAGE->requires->css(new \moodle_url('/local/edubot/assets/edubot.css'));
    }

    /**
     * Inject EduBot chatbot HTML and JS into the page footer.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $USER, $COURSE, $CFG, $DB;

        // Check if plugin is enabled.
        if (!get_config('local_edubot', 'plugin_enabled')) {
            return;
        }

        // Check if user is logged in.
        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Only show chatbot inside course pages (courseid > 1).
        if (!$COURSE || $COURSE->id <= 1) {
            return;
        }

        $pagetype = $PAGE->pagetype ?? '';

        // Do NOT inject chatbot HTML/JS during an active quiz attempt.
        // The chatbot must only appear after the student submits the quiz
        // (i.e., on the quiz review page: mod-quiz-review).
        if (strpos($pagetype, 'mod-quiz-attempt') !== false ||
            strpos($pagetype, 'mod-quiz-view') !== false) {
            return;
        }

        $courseid = (int) $COURSE->id;
        $wwwroot = $CFG->wwwroot;
        $sesskey = sesskey();

        // Inject KaTeX CSS
        $hook->add_html('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css" crossorigin="anonymous">');

        // Load JS.
        $PAGE->requires->js_call_amd('local_edubot/edubot', 'init', [
            $wwwroot,
            $USER->id,
            $courseid,
            $sesskey,
        ]);

        // Detect quiz review page so we can trigger the debrief session.
        $attemptid = 0;
        $isquizreview = strpos($pagetype, 'mod-quiz-review') !== false;
        if ($isquizreview) {
            $attemptid = optional_param('attempt', 0, PARAM_INT);
        }

        // Query the last PDF uploaded in this course for the activation greeting.
        // Only used on non-quiz course pages (course view, resource pages, etc.).
        $lastpdf = '';
        $coursefullname = '';
        try {
            $courserecord = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
            if ($courserecord) {
                $coursefullname = $courserecord->fullname;
            }

            // Only fetch and show the last-PDF greeting on regular course pages,
            // NOT on quiz-related pages (view, attempt, review).
            $isonquizpage = strpos($pagetype, 'mod-quiz') !== false;
            if (!$isonquizpage) {
                $pdfsql = "SELECT f.id, f.filename
                             FROM {files} f
                             JOIN {context} ctx ON ctx.id = f.contextid
                             JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = 70
                            WHERE cm.course = :courseid
                              AND f.component = 'mod_resource'
                              AND f.mimetype = 'application/pdf'
                              AND f.filename <> '.'
                         ORDER BY f.timemodified DESC
                            LIMIT 1";
                $pdfrecord = $DB->get_record_sql($pdfsql, ['courseid' => $courseid]);
                if ($pdfrecord) {
                    $lastpdf = $pdfrecord->filename;
                }
            }
        } catch (\Exception $e) {
            // Silently ignore — greeting will fall back to generic welcome.
            debugging('EduBot: Could not query last PDF for course greeting: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Inject the floating button and panel HTML.
        require_once($CFG->dirroot . '/local/edubot/lib.php');
        $html = \local_edubot_render_html($courseid, $USER->id, $wwwroot, $sesskey, $attemptid, $lastpdf, $coursefullname);
        $hook->add_html($html);
    }
}
