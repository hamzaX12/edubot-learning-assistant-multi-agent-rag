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
 * AJAX endpoint: delete conversation.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

$conversationid = required_param('conversation_id', PARAM_INT);

// Verify ownership of the conversation.
$conversation = $DB->get_record('local_edubot_conversations', [
    'id' => $conversationid,
    'userid' => $USER->id,
]);

if (!$conversation) {
    echo json_encode([
        'success' => false,
        'error' => 'Conversation not found or access denied.'
    ]);
    die();
}

try {
    // Start delegated transaction.
    $transaction = $DB->start_delegated_transaction();

    // Delete all messages associated with the conversation.
    $DB->delete_records('local_edubot_messages', ['conversation_id' => $conversationid]);

    // Delete the conversation itself.
    $DB->delete_records('local_edubot_conversations', ['id' => $conversationid]);

    $transaction->allow_commit();

    echo json_encode([
        'success' => true
    ]);
} catch (Exception $e) {
    if (isset($transaction)) {
        try {
            $transaction->rollback($e);
        } catch (Exception $ex) {
            // Transaction might already be rolled back or not initialized.
        }
    }
    echo json_encode([
        'success' => false,
        'error' => 'Failed to delete conversation: ' . $e->getMessage()
    ]);
}
die();
