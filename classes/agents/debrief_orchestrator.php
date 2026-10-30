<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Agent Orchestrator for the Post-Quiz Intelligent Debrief System.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class debrief_orchestrator {
    
    /**
     * Handle incoming debrief messages.
     */
    public function handle($userid, $courseid, $message, $conversation_id, $attemptid = null) {
        global $DB, $CFG;

        $now = time();
        $is_new_session = false;

        // 1. Load or create conversation.
        if (empty($conversation_id)) {
            $is_new_session = true;
            
            // Extract quiz data if this is the start of a debrief.
            if (empty($attemptid)) {
                throw new \Exception("Attempt ID required to start a debrief session.");
            }
            $quiz_data = \local_edubot\quiz_debrief_helper::extract_attempt_data($attemptid);
            if (!$quiz_data) {
                throw new \Exception("Could not extract quiz data.");
            }
            
            $wrong_questions = array_filter($quiz_data['questions'], function($q) {
                return !$q['is_correct'];
            });
            
            // Initialize state.
            $state = [
                'phase' => 1,
                'quiz_data' => $quiz_data,
                'wrong_questions' => array_values($wrong_questions),
                'current_question_index' => 0,
                'remediation_plan' => [],
                'mini_test_questions' => [],
                'mini_test_index' => 0,
            ];

            // Setup conversation title.
            $title = $quiz_data['quiz_name'] . ' — ' . date('d M Y');

            $conv = new \stdClass();
            $conv->userid = $userid;
            $conv->courseid = $courseid;
            $conv->title = $title;
            $conv->topic = 'Quiz Debrief';
            $conv->conversation_type = 'quiz_debrief';
            $conv->context_data = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE);
            $conv->created_at = $now;
            $conv->updated_at = $now;
            $conversation_id = $DB->insert_record('local_edubot_conversations', $conv);
            $conv->id = $conversation_id;
            
            // System message representing the "initialization".
            // Since the frontend just sends an empty init message, we don't save the user message.
        } else {
            $conv = $DB->get_record('local_edubot_conversations', ['id' => $conversation_id]);
            if (!$conv || $conv->userid != $userid) {
                throw new \Exception("Invalid conversation.");
            }
            $state = json_decode($conv->context_data, true);
            if (!is_array($state)) {
                $state = ['phase' => 1, 'current_question_index' => 0, 'wrong_questions' => []]; // Safe fallback
            }

            // Save user message.
            $msg = new \stdClass();
            $msg->conversation_id = $conversation_id;
            $msg->role = 'user';
            $msg->content = $message;
            $msg->created_at = $now;
            $DB->insert_record('local_edubot_messages', $msg);
        }

        // 2. State Machine.
        $gemini = new \local_edubot\gemini_client();
        $profiler = new \local_edubot\student_profiler();
        $profile = $profiler->get_full_context($userid, $courseid, $conversation_id);

        $response = "";
        $next_phase = $state['phase'];

        if ($state['phase'] == 1) {
            // PHASE 1: Reception.
            $pct = $state['quiz_data']['percentage'];
            
            if ($pct >= 80) {
                $sys_prompt = "The student scored " . round($pct) . "% on the quiz '" . $state['quiz_data']['quiz_name'] . "'. Congratulate them. Name some concepts they mastered. Keep it short. End by wishing them well on the next topic.";
                $response = $gemini->send_message([], $sys_prompt, "Hello!");
                $next_phase = 5; // End session
            } else if ($pct < 50) {
                $sys_prompt = "The student scored " . round($pct) . "% on the quiz '" . $state['quiz_data']['quiz_name'] . "'. Use EMOTIONAL SUPPORT strategy. Validate it was hard, normalize struggle. Acknowledge effort. Ask them if they are ready to review the mistakes.";
                $response = $gemini->send_message([], $sys_prompt, "Hello!");
                $next_phase = 2; // Transition to error analysis after this
            } else {
                // 50-79%
                $sys_prompt = "The student scored " . round($pct) . "% on the quiz '" . $state['quiz_data']['quiz_name'] . "'. Acknowledge partial success. Tell them they missed " . count($state['wrong_questions']) . " questions. Ask if they are ready to review the first mistake.";
                $response = $gemini->send_message([], $sys_prompt, "Hello!");
                $next_phase = 2; 
            }
            
        } else if ($state['phase'] == 2) {
            // PHASE 2: Question-by-Question Error Explanation.
            $idx = $state['current_question_index'];
            if ($idx >= count($state['wrong_questions'])) {
                // All questions reviewed. Move to Phase 3.
                $state['phase'] = 3;
                $next_phase = 3;
                // We will handle phase 3 immediately.
            } else {
                $q = $state['wrong_questions'][$idx];
                
                // Has it been explained yet?
                // We determine if we should explain or check confirmation based on message history.
                // To keep it simple, we use a single prompt that explains the question and asks for confirmation.
                $sys_prompt = "You are explaining a wrong answer. Student selected '{$q['student_answer']}'. Correct was '{$q['correct_answer']}'. \n";
                $sys_prompt .= "Question: {$q['question_text']}\n";
                $sys_prompt .= "Concept: {$q['concept']}\n";
                
                // RAG retrieval.
                $rag = new \local_edubot\rag_engine();
                $chunks = $rag->retrieve_relevant_chunks($q['question_text'] . ' ' . $q['concept'], $courseid, 2);
                $context = "No PDF context found.";
                if (!empty($chunks)) {
                    $context = "PDF SECTION: " . $chunks[0]['source'] . " (Page " . $chunks[0]['page_number'] . ")\n" . $chunks[0]['text'];
                }
                
                $sys_prompt .= "Context:\n" . $context . "\n\n";
                $sys_prompt .= "Instructions:\n";
                $sys_prompt .= "0. You MUST respond in English regardless of the language of the question.\n";
                $sys_prompt .= "1. Explain what happened (they picked X, correct is Y).\n";
                $sys_prompt .= "2. Explain WHY their answer is wrong conceptually.\n";
                $sys_prompt .= "3. Explain the correct concept. Adapt to learning style: " . json_encode($profile['learning_style']) . ".\n";
                $sys_prompt .= "4. Reference the exact PDF document name and page number from the context.\n";
                $sys_prompt .= "5. Ask a micro-confirmation question to verify understanding.\n";
                $sys_prompt .= "6. FORMATTING: Be concise. Do NOT use excessive vertical spacing or multiple consecutive blank lines. Keep paragraphs tightly grouped like a normal chat message.\n";
                $sys_prompt .= "7. IMPORTANT: If the user's message correctly answers your PREVIOUS micro-confirmation and shows they understand, you MUST append the exact text `[NEXT_QUESTION]` at the very end of your response to signal the system to move on.\n";

                // We get the chat history.
                $history = $this->get_history($conversation_id);
                $response = $gemini->send_message($history, $sys_prompt, $message);
                
                if (strpos($response, '[NEXT_QUESTION]') !== false) {
                    $response = str_replace('[NEXT_QUESTION]', '', $response);
                    $state['current_question_index']++;
                    if ($state['current_question_index'] < count($state['wrong_questions'])) {
                        $response .= "\n\n**Great job! Let's move on to the next question.**";
                    }
                }
            }
        } 
        
        if ($state['phase'] == 3) {
            // PHASE 3: Remediation Plan.
            $sys_prompt = "Generate a personalized remediation plan. The student got these concepts wrong:\n";
            foreach ($state['wrong_questions'] as $q) {
                $sys_prompt .= "- " . $q['concept'] . "\n";
            }
            $sys_prompt .= "List each concept, why to review it, suggested practice activity, and estimated time. Format as a clear Markdown list. Then ask if they want to take a 3-question mini re-test.";
            
            $history = $this->get_history($conversation_id);
            $response = $gemini->send_message($history, $sys_prompt, "Generate the remediation plan.");
            $next_phase = 4;
        }

        if ($state['phase'] == 4) {
            // PHASE 4: Mini Re-Test.
            $history = $this->get_history($conversation_id);
            $sys_prompt = "You are administering a 3-question mini re-test based on these missed concepts:\n";
            foreach ($state['wrong_questions'] as $q) {
                $sys_prompt .= "- " . $q['concept'] . "\n";
            }
            $sys_prompt .= "If the user accepts the test, give them 3 new questions one by one. If they decline, end the session.";
            
            $response = $gemini->send_message($history, $sys_prompt, $message);
        }

        // Save state.
        $state['phase'] = $next_phase;
        $conv->context_data = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE);
        $conv->updated_at = $now;
        $DB->update_record('local_edubot_conversations', $conv);

        // Save bot message.
        $botmsg = new \stdClass();
        $botmsg->conversation_id = $conversation_id;
        $botmsg->role = 'assistant';
        $botmsg->content = $response;
        $botmsg->created_at = $now;
        $DB->insert_record('local_edubot_messages', $botmsg);

        return json_encode([
            'success' => true,
            'message' => $response,
            'conversation_id' => $conversation_id,
            'phase' => $next_phase
        ]);
    }

    private function get_history($conversation_id) {
        global $DB;
        $records = $DB->get_records('local_edubot_messages', ['conversation_id' => $conversation_id], 'created_at ASC');
        $history = [];
        foreach ($records as $r) {
            $history[] = [
                'role' => $r->role,
                'content' => $r->content
            ];
        }
        return $history;
    }
}
