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
 * AJAX endpoint: create a new conversation.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);

// Verify course enrollment (skip for site-level courseid <= 1).
if ($courseid > 1) {
    $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
    if (!$coursecontext || !is_enrolled($coursecontext, $USER)) {
        echo json_encode(['success' => false, 'error' => get_string('error_not_enrolled', 'local_edubot')]);
        die();
    }
}

$now = time();

$record = new stdClass();
$record->userid = $USER->id;
$record->courseid = $courseid;
$record->title = '';
$record->topic = '';
$record->created_at = $now;
$record->updated_at = $now;

$id = $DB->insert_record('local_edubot_conversations', $record);

// Get the course name for the response.
$course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');

echo json_encode([
    'success' => true,
    'conversation_id' => $id,
    'courseid' => $courseid,
    'course_name' => $course ? $course->fullname : '',
    'created_at' => $now,
]);
