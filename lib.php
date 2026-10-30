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
 * Library functions and Moodle hooks for EduBot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Note: Hook callbacks for CSS injection (before_standard_head_html_generation)
// and footer HTML injection (before_footer_html_generation) are registered
// in db/hooks.php and implemented in classes/hook_callbacks.php.

/**
 * Render the chatbot HTML.
 */
function local_edubot_render_html($courseid, $userid, $wwwroot, $sesskey, $attemptid = 0, $lastpdf = '', $coursename = '') {
    $s = function($key) {
        return get_string($key, 'local_edubot');
    };

    $chaticon = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';

    $closeicon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

    $sendicon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>';

    // Sidebar close icon: chevron pointing left (≪ collapse)
    $sidebaricon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';

    // Sidebar open icon: 3-line menu / hamburger
    $sidebaropenicon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';

    $html = '';

    // Floating trigger button.
    $html .= '<button id="edubot-trigger-btn" class="edubot-trigger" ';
    $html .= 'data-courseid="' . $courseid . '" ';
    $html .= 'data-userid="' . $userid . '" ';
    $html .= 'data-wwwroot="' . $wwwroot . '" ';
    $html .= 'data-sesskey="' . $sesskey . '" ';
    if ($attemptid > 0) {
        $html .= 'data-debrief-attempt="' . $attemptid . '" ';
    }
    // Course entry activation: auto-open flag + last PDF + course name.
    if (!empty($lastpdf)) {
        $html .= 'data-auto-open="1" ';
        $html .= 'data-last-pdf="' . htmlspecialchars($lastpdf, ENT_QUOTES) . '" ';
    }
    if (!empty($coursename)) {
        $html .= 'data-course-name="' . htmlspecialchars($coursename, ENT_QUOTES) . '" ';
    }
    $html .= 'title="Open EduBot">';
    $html .= $chaticon;
    $html .= '</button>';

    // Dark overlay.
    $html .= '<div id="edubot-overlay" class="edubot-overlay"></div>';

    // Main panel.
    $html .= '<div id="local-edubot-panel" class="edubot-panel">';

    // Panel header.
    $html .= '<div class="edubot-panel-header">';
    $html .= '<div class="edubot-panel-title">';
    $html .= '<span class="edubot-logo">🤖</span> ' . $s('edubot_title');
    $html .= '</div>';
    $html .= '<button id="edubot-close-btn" class="edubot-close-btn">' . $closeicon . '</button>';
    $html .= '</div>';

    // Panel body (sidebar + chat).
    $html .= '<div class="edubot-panel-body">';

    // LEFT SIDEBAR.
    $html .= '<div id="edubot-sidebar" class="edubot-sidebar">';
    $html .= '<div class="edubot-sidebar-header">';
    $html .= '<h3>' . $s('conversations') . '</h3>';
    $html .= '<button id="edubot-sidebar-toggle" class="edubot-sidebar-toggle" title="Close sidebar" aria-label="Close sidebar">';
    $html .= $sidebaricon;
    $html .= '</button>';
    $html .= '</div>';
    $html .= '<button id="edubot-new-conv-btn" class="edubot-new-conv-btn" style="margin: 0 12px 8px;">';
    $html .= $s('new_conversation');
    $html .= '</button>';
    $html .= '<div id="edubot-conv-list" class="edubot-conv-list">';
    $html .= '<div class="edubot-empty-state">' . $s('no_conversations') . '</div>';
    $html .= '</div>';
    $html .= '</div>';

    // MAIN CHAT AREA.
    $html .= '<div class="edubot-chat-area">';

    // Chat header.
    $html .= '<div id="edubot-chat-header" class="edubot-chat-header">';
    $html .= '<button id="edubot-sidebar-open" class="edubot-sidebar-open-btn" title="Open sidebar" aria-label="Open sidebar">';
    $html .= $sidebaropenicon;
    $html .= '</button>';
    $html .= '<span id="edubot-chat-topic">Select or start a conversation</span>';
    $html .= '</div>';

    // Messages container.
    $html .= '<div id="edubot-messages" class="edubot-messages">';
    $html .= '<div class="edubot-welcome">';
    $html .= '<div class="edubot-welcome-icon">🤖</div>';
    $html .= '<p>' . $s('welcome_message') . '</p>';
    $html .= '</div>';
    $html .= '</div>';

    // Quick action buttons.
    $html .= '<div class="edubot-actions">';
    $actions = [
        'explain' => $s('action_explain'),
        'hint'    => $s('action_hint'),
        'example' => $s('action_example'),
        'quiz'    => $s('action_quiz'),
        'check'   => $s('action_check'),
    ];
    foreach ($actions as $action => $label) {
        $html .= '<button class="edubot-action-btn" data-action="' . $action . '">';
        $html .= $label . '</button>';
    }
    $html .= '</div>';

    // Input area.
    $html .= '<div class="edubot-input-area">';
    $html .= '<textarea id="edubot-input" class="edubot-input" ';
    $html .= 'placeholder="' . $s('type_message') . '" rows="1"></textarea>';
    $html .= '<button id="edubot-send-btn" class="edubot-send-btn" title="' . $s('send') . '">';
    $html .= $sendicon;
    $html .= '</button>';
    $html .= '</div>';

    $html .= '</div>'; // End chat area.
    $html .= '</div>'; // End panel body.
    $html .= '</div>'; // End panel.

    // Inline sidebar-toggle script — bypasses AMD caching entirely.
    $html .= '<script>';
    $html .= '(function() {';
    $html .= '  var STORAGE_KEY = "edubot_sidebar_open";';
    $html .= '  function getPanel() { return document.getElementById("local-edubot-panel"); }';
    $html .= '  function isMobile() { return window.innerWidth <= 768; }';

    $html .= '  function collapseSidebar() {';
    $html .= '    var p = getPanel(); if (!p) return;';
    $html .= '    p.classList.add("sidebar-collapsed");';
    $html .= '    localStorage.setItem(STORAGE_KEY, "false");';
    $html .= '    var btn = document.getElementById("edubot-sidebar-toggle");';
    $html .= '    if (btn) { btn.title = "Open sidebar"; btn.setAttribute("aria-label","Open sidebar"); }';
    $html .= '  }';

    $html .= '  function expandSidebar() {';
    $html .= '    var p = getPanel(); if (!p) return;';
    $html .= '    p.classList.remove("sidebar-collapsed");';
    $html .= '    localStorage.setItem(STORAGE_KEY, "true");';
    $html .= '    var btn = document.getElementById("edubot-sidebar-toggle");';
    $html .= '    if (btn) { btn.title = "Close sidebar"; btn.setAttribute("aria-label","Close sidebar"); }';
    $html .= '  }';

    $html .= '  function restoreState() {';
    $html .= '    if (isMobile()) { var p = getPanel(); if (p) p.classList.remove("sidebar-collapsed","sidebar-mobile-open"); return; }';
    $html .= '    var saved = localStorage.getItem(STORAGE_KEY);';
    $html .= '    if (saved === "false") { collapseSidebar(); } else { expandSidebar(); }';
    $html .= '  }';

    // Delegate click on document so it works whenever panel is opened.
    $html .= '  document.addEventListener("click", function(e) {';
    $html .= '    var p = getPanel();';
    $html .= '    if (!p) return;';
    // Close / toggle button inside sidebar.
    $html .= '    if (e.target.closest("#edubot-sidebar-toggle")) {';
    $html .= '      if (isMobile()) { p.classList.remove("sidebar-mobile-open"); }';
    $html .= '      else if (p.classList.contains("sidebar-collapsed")) { expandSidebar(); }';
    $html .= '      else { collapseSidebar(); }';
    $html .= '      e.stopImmediatePropagation();';
    $html .= '      return;';
    $html .= '    }';
    // Open button in chat header.
    $html .= '    if (e.target.closest("#edubot-sidebar-open")) {';
    $html .= '      if (isMobile()) { p.classList.add("sidebar-mobile-open"); }';
    $html .= '      else { expandSidebar(); }';
    $html .= '      e.stopImmediatePropagation();';
    $html .= '      return;';
    $html .= '    }';
    // Mobile backdrop.
    $html .= '    if (e.target.id === "edubot-mobile-sidebar-backdrop") {';
    $html .= '      p.classList.remove("sidebar-mobile-open");';
    $html .= '      e.stopImmediatePropagation();';
    $html .= '    }';
    $html .= '  });';

    // Restore state on load.
    $html .= '  if (document.readyState === "loading") {';
    $html .= '    document.addEventListener("DOMContentLoaded", restoreState);';
    $html .= '  } else { restoreState(); }';

    // Also restore when panel opens (trigger button clicked).
    $html .= '  document.addEventListener("click", function(e) {';
    $html .= '    if (e.target.closest("#edubot-trigger-btn")) {';
    $html .= '      setTimeout(restoreState, 50);';
    $html .= '    }';
    $html .= '  });';

    // Mobile backdrop injection.
    $html .= '  function injectBackdrop() {';
    $html .= '    if (!document.getElementById("edubot-mobile-sidebar-backdrop")) {';
    $html .= '      var bd = document.createElement("div");';
    $html .= '      bd.id = "edubot-mobile-sidebar-backdrop";';
    $html .= '      bd.className = "edubot-mobile-sidebar-backdrop";';
    $html .= '      var body = document.querySelector("#local-edubot-panel .edubot-panel-body");';
    $html .= '      if (body) body.insertBefore(bd, body.firstChild);';
    $html .= '    }';
    $html .= '  }';
    $html .= '  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", injectBackdrop); } else { injectBackdrop(); }';

    // MutationObserver: auto-remove the loading bubble when real message arrives.
    $html .= '  var _debriefObs = null;';
    $html .= '  function watchForDebriefMessage() {';
    $html .= '    var msgs = document.getElementById("edubot-messages");';
    $html .= '    if (!msgs) return;';
    $html .= '    if (_debriefObs) { _debriefObs.disconnect(); _debriefObs = null; }';
    $html .= '    _debriefObs = new MutationObserver(function(muts) {';
    $html .= '      muts.forEach(function(m) {';
    $html .= '        m.addedNodes.forEach(function(node) {';
    $html .= '          if (node.nodeType !== 1) return;';
    $html .= '          if (!node.classList.contains("edubot-msg") || !node.classList.contains("assistant")) return;';
    $html .= '          var bubble = node.querySelector(".edubot-msg-bubble");';
    $html .= '          if (!bubble) return;';
    // If this new bubble is NOT a typing indicator, remove any previous typing bubbles.
    $html .= '          if (!bubble.querySelector(".edubot-typing")) {';
    $html .= '            var all = msgs.querySelectorAll(".edubot-msg.assistant");';
    $html .= '            all.forEach(function(el) {';
    $html .= '              if (el === node) return;';
    $html .= '              var b = el.querySelector(".edubot-msg-bubble");';
    $html .= '              if (b && b.querySelector(".edubot-typing")) {';
    $html .= '                var label = el.previousElementSibling;';
    $html .= '                if (label && label.classList.contains("edubot-msg-label")) { label.remove(); }';
    $html .= '                el.remove();';
    $html .= '              }';
    $html .= '            });';
    $html .= '          }';
    $html .= '        });';
    $html .= '      });';
    $html .= '    });';
    $html .= '    _debriefObs.observe(msgs, { childList: true });';
    $html .= '  }';
    // Start watching as soon as DOM is ready.
    $html .= '  if (document.readyState === "loading") {';
    $html .= '    document.addEventListener("DOMContentLoaded", watchForDebriefMessage);';
    $html .= '  } else { watchForDebriefMessage(); }';
    // Re-attach observer each time the panel opens (new debrief or new chat).
    $html .= '  document.addEventListener("click", function(e) {';
    $html .= '    if (e.target.closest("#edubot-trigger-btn")) { setTimeout(watchForDebriefMessage, 200); }';
    $html .= '  });';

    $html .= '})();';
    $html .= '</script>';

    return $html;
}
