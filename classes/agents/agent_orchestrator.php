<?php
namespace local_edubot\agents;

use stdClass;

defined('MOODLE_INTERNAL') || die();

class agent_orchestrator {
    public function handle($userid, $courseid, $message, $conversation_id, $button_action) {
        global $DB, $CFG;
        $now = time();

        try {
            // Load conversation to verify it exists and to potentially update it later
            $conversation = $DB->get_record('local_edubot_conversations', [
                'id' => $conversation_id,
                'userid' => $userid,
            ]);
            if (!$conversation) {
                return json_encode(['success' => false, 'error' => 'Invalid conversation.']);
            }

            // Load conversation history
            $history = [];
            $messages = $DB->get_records('local_edubot_messages', [
                'conversation_id' => $conversation_id,
            ], 'created_at ASC');

            foreach ($messages as $msg) {
                $history[] = [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            }

            // 1. DiagnosticAgent
            $diagnostic = new diagnostic_agent();
            $student_state = $diagnostic->run($userid, $courseid, $conversation_id, $message);

            // 2. ContentAgent
            $content_agent = new content_agent();
            $content = $content_agent->run($message, $courseid, $button_action);

            // Setup loop for Pedagogical and Reflection
            $pedagogical = new pedagogical_agent();
            $reflection = new reflection_agent();

            $max_retries = 2;
            $attempts = 0;
            $retry_feedback = '';
            
            $final_response = [];

            while ($attempts <= $max_retries) {
                $attempts++;
                
                // 3. PedagogicalAgent
                $pedagogical_output = $pedagogical->run(
                    $student_state,
                    $content,
                    $history,
                    $message,
                    $button_action,
                    $retry_feedback
                );

                // If it's the last allowed attempt, force approval
                if ($attempts > $max_retries) {
                    $final_response = $pedagogical_output;
                    break;
                }

                // 4. ReflectionAgent
                $reflection_output = $reflection->run(
                    $pedagogical_output['raw_response'],
                    $pedagogical_output['strategy_used'],
                    $student_state['affective_state']
                );

                if ($reflection_output['is_approved']) {
                    $final_response = $pedagogical_output;
                    break;
                } else {
                    $retry_feedback = $reflection_output['retry_feedback'];
                    // Fall back to final response if the loop is about to end
                    $final_response = $pedagogical_output;
                }
            }

            $bot_message = $final_response['raw_response'];
            $strategy_used = $final_response['strategy_used'];
            $emotion_detected = $final_response['emotion_detected'];

            // Save user message
            $usermsg = new stdClass();
            $usermsg->conversation_id = $conversation_id;
            $usermsg->role = 'user';
            $usermsg->content = $message;
            $usermsg->strategy_used = $strategy_used;
            $usermsg->emotion_detected = $emotion_detected;
            $usermsg->created_at = $now;
            $DB->insert_record('local_edubot_messages', $usermsg);

            // Save bot response
            $botmsg = new stdClass();
            $botmsg->conversation_id = $conversation_id;
            $botmsg->role = 'assistant';
            $botmsg->content = $bot_message;
            $botmsg->strategy_used = $strategy_used;
            $botmsg->emotion_detected = $emotion_detected;
            $botmsg->created_at = $now + 1;
            $DB->insert_record('local_edubot_messages', $botmsg);

            // Update conversation
            $updateconv = new stdClass();
            $updateconv->id = $conversation_id;
            $updateconv->updated_at = $now;

            if (empty($conversation->title)) {
                $updateconv->title = substr($message, 0, 40);
            }
            if (empty($conversation->topic)) {
                $updateconv->topic = substr($message, 0, 100);
            }

            $DB->update_record('local_edubot_conversations', $updateconv);

            return json_encode([
                'success' => true,
                'message' => $bot_message,
                'strategy' => $strategy_used,
                'emotion' => $emotion_detected,
            ]);

        } catch (\Exception $e) {
            debugging('EduBot Orchestrator error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return json_encode([
                'success' => false,
                'error' => get_string('error_api_failed', 'local_edubot'),
                'debug' => ($CFG->debugdisplay ? $e->getMessage() : ''),
            ]);
        }
    }
}
