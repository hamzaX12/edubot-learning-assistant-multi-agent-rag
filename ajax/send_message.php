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
 * AJAX endpoint: send a message to EduBot.
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
    $message = required_param('message', PARAM_RAW);
    $conversationid = required_param('conversation_id', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $buttonaction = optional_param('button_action', '', PARAM_ALPHA);

    $message = clean_text($message);
    $now = time();

    // Verify course access — for site-level (courseid <= 1), just check login.
    if ($courseid > 1) {
        $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext || !is_enrolled($coursecontext, $USER)) {
            echo json_encode(['success' => false, 'error' => get_string('error_not_enrolled', 'local_edubot')]);
            die();
        }
    }

    $is_debrief = false;
    $attemptid = optional_param('attemptid', 0, PARAM_INT);
    
    // Check if this is an existing debrief conversation or a new one.
    if ($conversationid > 0) {
        $conv = $DB->get_record('local_edubot_conversations', ['id' => $conversationid]);
        if ($conv && $conv->conversation_type === 'quiz_debrief') {
            $is_debrief = true;
        }
    } else if ($attemptid > 0) {
        $is_debrief = true;
    }

    if ($is_debrief) {
        $orchestrator = new \local_edubot\agents\debrief_orchestrator();
        $result = $orchestrator->handle($USER->id, $courseid, $message, $conversationid, $attemptid);
    } else {
        $orchestrator = new \local_edubot\agents\agent_orchestrator();
        $result = $orchestrator->handle($USER->id, $courseid, $message, $conversationid, $buttonaction);
    }
    
    echo $result;

} catch (\Exception $e) {
    debugging('EduBot AJAX error: ' . $e->getMessage() . ' in ' . $e->getFile()
        . ':' . $e->getLine(), DEBUG_DEVELOPER);
    echo json_encode([
        'success' => false,
        'error' => 'API Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(),
        'debug' => ($CFG->debugdisplay ? $e->getMessage() : ''),
    ]);
}
