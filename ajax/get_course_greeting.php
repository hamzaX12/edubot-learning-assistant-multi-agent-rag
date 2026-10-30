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
 * AJAX endpoint: get course greeting data (last PDF + course name).
 *
 * Returns the last PDF uploaded by a teacher in the course, used by
 * the auto-open activation greeting shown when a student enters a course.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json');

try {
    $courseid = required_param('courseid', PARAM_INT);

    // Verify course access.
    if ($courseid <= 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid course.']);
        die();
    }

    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if (!$coursecontext || !is_enrolled($coursecontext, $USER)) {
        echo json_encode(['success' => false, 'error' => get_string('error_not_enrolled', 'local_edubot')]);
        die();
    }

    // Get course full name.
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', MUST_EXIST);

    // Find the most recently modified PDF file in this course (uploaded via mod_resource).
    $sql = "SELECT f.id, f.filename, f.timemodified
              FROM {files} f
              JOIN {context} ctx ON ctx.id = f.contextid
              JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = 70
             WHERE cm.course = :courseid
               AND f.component = 'mod_resource'
               AND f.mimetype = 'application/pdf'
               AND f.filename <> '.'
          ORDER BY f.timemodified DESC
             LIMIT 1";

    $lastpdf = $DB->get_record_sql($sql, ['courseid' => $courseid]);

    // Check if course is indexed in the RAG engine.
    $isindexed = $DB->record_exists('local_edubot_rag_chunks', ['courseid' => $courseid]);

    echo json_encode([
        'success'     => true,
        'course_name' => $course->fullname,
        'has_pdf'     => !empty($lastpdf),
        'last_pdf'    => $lastpdf ? $lastpdf->filename : '',
        'is_indexed'  => $isindexed,
    ]);

} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'Error: ' . $e->getMessage(),
    ]);
}
