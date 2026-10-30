<?php
namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class pedagogical_agent {
    public function run($student_state, $content, $history, $message, $button_action, $retry_feedback = '') {
        $strategy = $this->select_strategy($student_state, $button_action, $message);

        $gemini = new \local_edubot\gemini_client();
        $constraint_mode = get_config('local_edubot', 'constraint_mode') ?: 'GUIDED';

        $system_prompt = $gemini->build_system_prompt(
            $student_state, 
            $content['rag_context'], 
            $strategy, 
            $constraint_mode
        );

        if (!empty($content['action_instruction'])) {
            $system_prompt .= "\n=== ACTION INSTRUCTION ===\n" . $content['action_instruction'] . "\n";
        }

        if (!empty($retry_feedback)) {
            $system_prompt .= "\n=== RETRY FEEDBACK ===\n" . $retry_feedback . "\n";
        }

        // We need to update hint_level if button was hint (handled by strategy selector previously, but agent 2 might need to increment it. Wait, the prompt didn't say to increment it, just select strategy based on it. We'll leave incrementing out unless specified).
        // Actually, the requirements said: "If button is 'hint' → ... instruction tells LLM...". It doesn't mention incrementing DB hint_level. Let's stick strictly to the prompt logic.

        $raw_response = $gemini->send_message($history, $system_prompt, $message);

        return [
            'raw_response' => $raw_response,
            'strategy_used' => $strategy,
            'emotion_detected' => $student_state['affective_state']
        ];
    }

    private function select_strategy($state, $button_action, $message) {
        if (!empty($button_action)) {
            switch ($button_action) {
                case 'hint': return 'SCAFFOLDED_HINTS';
                case 'check': return 'METACOGNITIVE_MONITORING';
                case 'explain': return 'ACTIVATION';
                case 'quiz': return 'CONSOLIDATION';
                case 'example': return 'ERROR_CORRECTION';
            }
        }

        $affective = $state['affective_state'] ?? 'NEUTRAL';
        $failed = $state['behavioral_signals']['failed_attempts_today'] ?? 0;
        
        if (in_array($affective, ['FRUSTRATED', 'ANXIOUS', 'DISCOURAGED']) || $failed >= 3) {
            return 'EMOTIONAL_SUPPORT';
        }

        $exchanges = $state['exchange_count'] ?? 0;
        if ($exchanges == 0 || $exchanges == 1) {
            return 'ACTIVATION';
        }

        $detector = new \local_edubot\emotion_detector();
        $msganalysis = $detector->analyze_message($message, $exchanges);
        $last_answer_wrong = $msganalysis['is_wrong_answer'];
        $hint_level = $state['hint_level'] ?? 0;

        if ($last_answer_wrong && $hint_level == 0) {
            return 'ERROR_CORRECTION';
        }

        if ($last_answer_wrong && $hint_level >= 1) {
            return 'SCAFFOLDED_HINTS';
        }

        if ($exchanges > 0 && ($exchanges % 5) == 0) {
            return 'METACOGNITIVE_MONITORING';
        }

        $completion = $state['topic_completion'] ?? 0;
        $recent_correct = $state['recent_correct_answers'] ?? 0;

        if ($completion >= 80 && $recent_correct >= 3) {
            return 'CONSOLIDATION';
        }

        return 'SOCRATIC_QUESTIONING';
    }
}
