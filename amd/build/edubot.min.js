/**
 * EduBot chatbot — AMD module for Moodle.
 *
 * @module     local_edubot/edubot
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_edubot/edubot', [
    'jquery',
    'core/ajax',
    'local_edubot/marked',
    'local_edubot/katex',
    'local_edubot/auto_render'
], function($, ajax, marked, katex, renderMathInElement) {

    console.error("EDUBOT AMD LOADED. marked:", typeof marked, "katex:", typeof katex, "renderMathInElement:", typeof renderMathInElement);

    // Ensure they are available globally for KaTeX internal references if needed
    window.marked = marked;
    window.katex = katex;
    window.renderMathInElement = renderMathInElement;
    var WWWROOT = '';
    var USERID = 0;
    var COURSEID = 0;
    var SESSKEY = '';
    var currentConversationId = 0;
    var isLoading = false;
    var activeAction = '';

    /**
     * Initialize the EduBot chatbot.
     */
    function init(wwwroot, userid, courseid, sesskey) {
        WWWROOT = wwwroot;
        USERID = userid;
        COURSEID = courseid;
        SESSKEY = sesskey;

        bindEvents();
        loadConversations();
        initSidebarToggle();

        // Read activation data from trigger button data attributes (set by PHP).
        var $trigger = $('#edubot-trigger-btn');
        var attemptId = $trigger.attr('data-debrief-attempt');
        var autoOpen  = $trigger.attr('data-auto-open') === '1';
        var lastPdf   = $trigger.attr('data-last-pdf') || '';
        var courseName = $trigger.attr('data-course-name') || '';

        if (attemptId && attemptId > 0) {
            // Quiz debrief takes priority over course activation.
            openPanel();
            startDebriefSession(attemptId);
        } else if (autoOpen) {
            // Auto-open with course entry activation greeting.
            setTimeout(function() {
                openPanel();
                showCourseActivation(lastPdf, courseName);
            }, 600);
        }
    }

    /**
     * Bind all UI event handlers.
     */
    function bindEvents() {
        // Open panel.
        $('#edubot-trigger-btn').on('click', function() {
            openPanel();
        });

        // Close panel.
        $('#edubot-close-btn').on('click', function() {
            closePanel();
        });

        // Overlay click closes panel.
        $('#edubot-overlay').on('click', function() {
            closePanel();
        });

        // New conversation.
        $('#edubot-new-conv-btn').on('click', function() {
            newConversation();
        });

        // Send message.
        $('#edubot-send-btn').on('click', function() {
            sendMessage();
        });

        // Enter to send, Shift+Enter for new line.
        $('#edubot-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea.
        $('#edubot-input').on('input', function() {
            autoResize(this);
        });

        // Quick action buttons.
        $(document).on('click', '.edubot-action-btn', function() {
            var action = $(this).data('action');
            var text = $(this).text();

            // Toggle active state.
            if (activeAction === action) {
                $(this).removeClass('active');
                activeAction = '';
                $('#edubot-input').val('');
            } else {
                $('.edubot-action-btn').removeClass('active');
                $(this).addClass('active');
                activeAction = action;
                $('#edubot-input').val(text).focus();
            }
        });

        // Activation suggestion chips — pre-fill input and focus.
        $(document).on('click', '.edubot-chip', function() {
            var text = $(this).data('text');
            $('#edubot-input').val(text).focus();
            autoResize($('#edubot-input')[0]);
            // Remove the activation card after a chip is clicked.
            $('.edubot-activation').fadeOut(200, function() { $(this).remove(); });
        });

        // Conversation item click.
        $(document).on('click', '.edubot-conv-item', function() {
            var convId = $(this).data('id');
            loadConversation(convId);
        });

        // Toggle conversation actions menu dropdown.
        $(document).on('click', '.edubot-conv-actions-btn', function(e) {
            e.stopPropagation(); // Prevent loading the conversation.
            var $menu = $(this).siblings('.edubot-conv-menu');
            $('.edubot-conv-menu').not($menu).removeClass('show');
            $menu.toggleClass('show');
        });

        // Hide menus when clicking outside.
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.edubot-conv-actions').length) {
                $('.edubot-conv-menu').removeClass('show');
            }
        });

        // Trigger conversation deletion.
        $(document).on('click', '.edubot-delete-conv-btn', function(e) {
            e.stopPropagation(); // Prevent loading the conversation.
            var $item = $(this).closest('.edubot-conv-item');
            var convId = $item.data('id');
            if (confirm('Are you sure you want to delete this conversation? This action cannot be undone.')) {
                deleteConversation(convId, $item);
            }
        });
    }

    /**
     * Collapse sidebar and save preference.
     */
    function collapseSidebar($panel) {
        $panel.addClass('sidebar-collapsed');
        localStorage.setItem('edubot_sidebar_open', 'false');
        $('#edubot-sidebar-toggle').attr('title', 'Open sidebar').attr('aria-label', 'Open sidebar');
    }

    /**
     * Expand sidebar and save preference.
     */
    function expandSidebar($panel) {
        $panel.removeClass('sidebar-collapsed');
        localStorage.setItem('edubot_sidebar_open', 'true');
        $('#edubot-sidebar-toggle').attr('title', 'Close sidebar').attr('aria-label', 'Close sidebar');
    }

    /**
     * Initialize sidebar state from localStorage and set up resize handler.
     */
    function initSidebarToggle() {
        var $panel = $('#local-edubot-panel');
        var STORAGE_KEY = 'edubot_sidebar_open';

        // Inject mobile backdrop element once.
        if ($('#edubot-mobile-sidebar-backdrop').length === 0) {
            $panel.find('.edubot-panel-body').prepend(
                '<div id="edubot-mobile-sidebar-backdrop" class="edubot-mobile-sidebar-backdrop"></div>'
            );
        }

        function restoreState() {
            if (window.innerWidth <= 768) {
                $panel.removeClass('sidebar-collapsed sidebar-mobile-open');
                return;
            }
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'false') {
                collapseSidebar($panel);
            } else {
                expandSidebar($panel);
            }
        }

        // Handle resize: switch between desktop/mobile modes.
        $(window).on('resize.edubotSidebar', function() {
            if (window.innerWidth <= 768) {
                $panel.removeClass('sidebar-collapsed sidebar-mobile-open');
            } else {
                $panel.removeClass('sidebar-mobile-open');
                restoreState();
            }
        });

        restoreState();
    }

    /**
     * Open the chatbot panel.
     */
    function openPanel() {
        $('#local-edubot-panel').addClass('open');
        $('#edubot-overlay').addClass('active');
        $('#edubot-trigger-btn').addClass('hidden');
        $('#edubot-input').focus();
    }

    /**
     * Close the chatbot panel.
     */
    function closePanel() {
        $('#local-edubot-panel').removeClass('open');
        $('#edubot-overlay').removeClass('active');
        $('#edubot-trigger-btn').removeClass('hidden');
    }

    /**
     * Auto-resize textarea.
     */
    function autoResize(el) {
        el.style.height = 'auto';
        var maxHeight = 96; // ~4 lines.
        el.style.height = Math.min(el.scrollHeight, maxHeight) + 'px';
    }

    /**
     * Load conversations list.
     */
    function loadConversations() {
        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/get_history.php',
            method: 'GET',
            data: {
                sesskey: SESSKEY,
                courseid: COURSEID
            },
            dataType: 'json',
            success: function(data) {
                if (data.success && data.conversations) {
                    renderConversationList(data.conversations);
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to load conversations', error);
            }
        });
    }

    /**
     * Render conversation list in sidebar.
     */
    function renderConversationList(conversations) {
        var $list = $('#edubot-conv-list');
        $list.empty();

        if (conversations.length === 0) {
            $list.html('<div class="edubot-empty-state">No conversations yet. Start a new one!</div>');
            return;
        }

        conversations.forEach(function(conv) {
            var title = conv.title || 'New conversation';
            if (title.length > 30) {
                title = title.substring(0, 30) + '...';
            }

            var date = new Date(conv.updated_at * 1000);
            var dateStr = date.toLocaleDateString() + ' ' +
                date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

            var activeClass = (conv.id === currentConversationId) ? ' active' : '';

            var dotOrIcon = '<span class="edubot-conv-dot"></span>';
            if (conv.conversation_type === 'quiz_debrief') {
                dotOrIcon = '<span style="margin-right:8px;font-size:12px;">✅</span>';
            }

            var html = '<div class="edubot-conv-item' + activeClass + '" data-id="' + conv.id + '">';
            html += '<div class="edubot-conv-actions">';
            html += '<button class="edubot-conv-actions-btn" aria-label="Conversation actions">';
            html += '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">';
            html += '<circle cx="3" cy="8" r="1.5"/>';
            html += '<circle cx="8" cy="8" r="1.5"/>';
            html += '<circle cx="13" cy="8" r="1.5"/>';
            html += '</svg>';
            html += '</button>';
            html += '<div class="edubot-conv-menu">';
            html += '<button class="edubot-conv-menu-item edubot-delete-conv-btn">';
            html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
            html += '<polyline points="3 6 5 6 21 6"></polyline>';
            html += '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>';
            html += '<line x1="10" y1="11" x2="10" y2="17"></line>';
            html += '<line x1="14" y1="11" x2="14" y2="17"></line>';
            html += '</svg>';
            html += '<span>Delete</span>';
            html += '</button>';
            html += '</div>';
            html += '</div>';
            html += '<div class="edubot-conv-title">' + escapeHtml(title) + '</div>';
            html += '<div class="edubot-conv-meta">';
            html += dotOrIcon;
            html += '<span>' + escapeHtml(conv.course_name || '') + '</span>';
            html += '</div>';
            html += '<div class="edubot-conv-meta">' + dateStr + '</div>';
            html += '</div>';

            $list.append(html);
        });
    }

    /**
     * Start a new conversation.
     */
    function newConversation() {
        var courseid = COURSEID || getPageCourseId();
        if (!courseid || courseid < 2) {
            // Use a default course ID if not on a course page.
            courseid = 1;
        }

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/new_conversation.php',
            method: 'POST',
            data: {
                sesskey: SESSKEY,
                courseid: courseid
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    currentConversationId = data.conversation_id;
                    clearChat();
                    showWelcome();
                    loadConversations();
                    updateChatHeader('New conversation', data.course_name || '');
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to create conversation', error);
            }
        });
    }

    /**
     * Start a debrief session.
     */
    function startDebriefSession(attemptId) {
        var courseid = COURSEID || getPageCourseId();
        if (!courseid || courseid < 2) {
            courseid = 1;
        }

        clearChat();
        showWelcome();
        showTyping();

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/send_message.php',
            method: 'POST',
            data: {
                sesskey: SESSKEY,
                courseid: courseid,
                message: 'start_debrief',
                conversation_id: 0,
                attemptid: attemptId
            },
            dataType: 'json',
            success: function(data) {
                hideTyping();
                clearChat();
                if (data.success) {
                    currentConversationId = data.conversation_id;
                    appendMessage('assistant', data.message, false, '');
                    loadConversations();
                } else {
                    appendMessage('assistant', data.error || 'Failed to start debrief.', false, '');
                }
            },
            error: function() {
                hideTyping();
                appendMessage('assistant', 'Sorry, failed to start the debrief. Please try again.', false, '');
            }
        });
    }

    /**
     * Load a specific conversation.
     */
    function loadConversation(convId) {
        currentConversationId = convId;

        // Highlight in sidebar.
        $('.edubot-conv-item').removeClass('active');
        $('.edubot-conv-item[data-id="' + convId + '"]').addClass('active');

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/get_history.php',
            method: 'GET',
            data: {
                sesskey: SESSKEY,
                conversation_id: convId
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    clearChat();
                    var conv = data.conversation;
                    updateChatHeader(conv.topic || conv.title || 'Conversation', '');

                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(function(msg) {
                            appendMessage(msg.role, msg.content, msg.strategy_used);
                        });
                        scrollToBottom();
                    } else {
                        showWelcome();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to load conversation', error);
            }
        });
    }

    /**
     * Delete a conversation.
     */
    function deleteConversation(convId, $item) {
        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/delete_conversation.php',
            method: 'POST',
            data: {
                sesskey: SESSKEY,
                conversation_id: convId
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    $item.fadeOut(300, function() {
                        $(this).remove();
                        if ($('#edubot-conv-list').children('.edubot-conv-item').length === 0) {
                            $('#edubot-conv-list').html('<div class="edubot-empty-state">No conversations yet. Start a new one!</div>');
                        }
                    });

                    if (convId === currentConversationId) {
                        currentConversationId = 0;
                        clearChat();
                        showWelcome();
                        updateChatHeader('Select or start a conversation', '');
                    }
                } else {
                    alert(data.error || 'Failed to delete conversation.');
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to delete conversation', error);
                alert('Connection error. Failed to delete conversation.');
            }
        });
    }

    /**
     * Send a message.
     */
    function sendMessage() {
        if (isLoading) {
            return;
        }

        var $input = $('#edubot-input');
        var message = $input.val().trim();
        if (!message) {
            return;
        }

        // Create conversation if none active.
        if (!currentConversationId) {
            var courseid = COURSEID || 1;
            $.ajax({
                url: WWWROOT + '/local/edubot/ajax/new_conversation.php',
                method: 'POST',
                data: {sesskey: SESSKEY, courseid: courseid},
                dataType: 'json',
                async: false,
                success: function(data) {
                    if (data.success) {
                        currentConversationId = data.conversation_id;
                    }
                }
            });
        }

        if (!currentConversationId) {
            return;
        }

        // Clear welcome and show user message.
        removeWelcome();
        appendMessage('user', message);
        $input.val('');
        $input.css('height', 'auto');
        activeAction = '';
        $('.edubot-action-btn').removeClass('active');

        // Show typing indicator.
        showTyping();
        isLoading = true;
        $('#edubot-send-btn').prop('disabled', true);

        var postData = {
            sesskey: SESSKEY,
            message: message,
            conversation_id: currentConversationId,
            courseid: COURSEID || 1
        };

        if (activeAction) {
            postData.button_action = activeAction;
        }

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/send_message.php',
            method: 'POST',
            data: postData,
            dataType: 'json',
            success: function(data) {
                hideTyping();
                isLoading = false;
                $('#edubot-send-btn').prop('disabled', false);

                if (data.success) {
                    appendMessage('assistant', data.message, data.strategy);
                    scrollToBottom();
                    loadConversations(); // Refresh sidebar.
                } else {
                    appendMessage('assistant', data.error || 'Something went wrong. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                hideTyping();
                isLoading = false;
                $('#edubot-send-btn').prop('disabled', false);
                console.error('EduBot: Send message failed', error);
                appendMessage('assistant', 'Connection error. Please try again.');
            }
        });
    }

    /**
     * Append a message to the chat area.
     */
    function appendMessage(role, content, strategy) {
        var $messages = $('#edubot-messages');

        var html = '<div class="edubot-msg ' + role + '">';

        if (role === 'assistant' && strategy) {
            html += '<div class="edubot-msg-strategy">' + escapeHtml(strategy) + '</div>';
        }

        if (role === 'assistant') {
            html += '<div class="edubot-msg-label">EduBot</div>';
        }

        html += '<div class="edubot-msg-bubble">' + formatMessage(content) + '</div>';
        html += '</div>';

        $messages.append(html);
        scrollToBottom();

        // Render KaTeX if available.
        if (typeof renderMathInElement === 'function') {
            try {
                // Find the specific message element we just appended.
                var lastMsg = $messages.children('.edubot-msg').last()[0];
                renderMathInElement(lastMsg, {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '\\[', right: '\\]', display: true},
                        {left: '\\(', right: '\\)', display: false},
                        {left: '$', right: '$', display: false}
                    ],
                    throwOnError: false
                });
            } catch (e) {
                console.warn('EduBot: KaTeX render failed', e);
            }
        }
    }

    /**
     * Format message content (basic markdown-like formatting).
     */
    function formatMessage(text) {
        if (typeof marked !== 'undefined') {
            var mathBlocks = [];
            
            // Extract display math $$ ... $$
            text = text.replace(/\$\$([\s\S]*?)\$\$/g, function(match, p1) {
                mathBlocks.push('$$' + p1 + '$$');
                return '%%%MATH_BLOCK_' + (mathBlocks.length - 1) + '%%%';
            });

            // Extract display math \[ ... \]
            text = text.replace(/\\\[([\s\S]*?)\\\]/g, function(match, p1) {
                mathBlocks.push('\\[' + p1 + '\\]');
                return '%%%MATH_BLOCK_' + (mathBlocks.length - 1) + '%%%';
            });
            
            // Extract inline math \( ... \)
            text = text.replace(/\\\(([\s\S]*?)\\\)/g, function(match, p1) {
                mathBlocks.push('\\(' + p1 + '\\)');
                return '%%%MATH_BLOCK_' + (mathBlocks.length - 1) + '%%%';
            });

            // Extract inline math $ ... $ (only if not preceded by a slash to avoid breaking escaping)
            text = text.replace(/(^|[^\\])\$([^\$]+?)\$/g, function(match, p1, p2) {
                mathBlocks.push('$' + p2 + '$');
                return p1 + '%%%MATH_BLOCK_' + (mathBlocks.length - 1) + '%%%';
            });

            // Parse markdown
            var html = marked.parse(text);
            
            // Re-inject math
            for (var i = 0; i < mathBlocks.length; i++) {
                html = html.replace('%%%MATH_BLOCK_' + i + '%%%', function() {
                    return mathBlocks[i];
                });
            }
            
            return html;
        }

        // Fallback if marked is somehow not loaded.
        var escaped = escapeHtml(text);
        escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        escaped = escaped.replace(/`(.*?)`/g, '<code>$1</code>');
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }

    /**
     * Show typing indicator.
     */
    function showTyping() {
        var html = '<div class="edubot-typing" id="edubot-typing-indicator">';
        html += '<div class="edubot-typing-dot"></div>';
        html += '<div class="edubot-typing-dot"></div>';
        html += '<div class="edubot-typing-dot"></div>';
        html += '</div>';
        $('#edubot-messages').append(html);
        scrollToBottom();
    }

    /**
     * Hide typing indicator.
     */
    function hideTyping() {
        $('#edubot-typing-indicator').remove();
    }

    /**
     * Show course entry activation greeting with last PDF name + suggestion chips.
     *
     * @param {string} pdfName  Filename of the last uploaded PDF (may be empty).
     * @param {string} courseName  Full name of the Moodle course.
     */
    function showCourseActivation(pdfName, courseName) {
        clearChat();

        var hasPdf = pdfName && pdfName.length > 0;
        var displayName = hasPdf
            ? pdfName.replace(/\.pdf$/i, '').replace(/_/g, ' ')
            : (courseName || 'this course');

        var headerText = hasPdf
            ? '📄 ' + escapeHtml(pdfName)
            : '📚 ' + escapeHtml(courseName || 'This Course');

        var bodyText = hasPdf
            ? 'I\'ve loaded <strong>' + escapeHtml(pdfName) + '</strong> for <em>' + escapeHtml(courseName) + '</em>.<br>What would you like to know about it?'
            : 'Welcome to <strong>' + escapeHtml(courseName || 'your course') + '</strong>!<br>I\'m here to help you learn. What would you like to explore?';

        // Suggestion chips.
        var chips = hasPdf
            ? [
                { text: 'Summarize this course material', icon: '📝' },
                { text: 'What are the main topics covered?', icon: '🔍' },
                { text: 'Explain the key concepts', icon: '💡' }
              ]
            : [
                { text: 'What topics are in this course?', icon: '🔍' },
                { text: 'Help me get started', icon: '🚀' },
                { text: 'Give me a course overview', icon: '📋' }
              ];

        var chipsHtml = '';
        chips.forEach(function(chip) {
            chipsHtml += '<button class="edubot-chip" data-text="' + escapeHtml(chip.text) + '">';
            chipsHtml += chip.icon + ' ' + escapeHtml(chip.text);
            chipsHtml += '</button>';
        });

        var html = '<div class="edubot-activation">';
        html += '<div class="edubot-activation-header">' + headerText + '</div>';
        html += '<div class="edubot-activation-body">' + bodyText + '</div>';
        html += '<div class="edubot-activation-chips">' + chipsHtml + '</div>';
        html += '</div>';

        $('#edubot-messages').html(html);
    }

    /**
     * Show welcome message.
     */
    function showWelcome() {
        var html = '<div class="edubot-welcome">';
        html += '<div class="edubot-welcome-icon">🤖</div>';
        html += '<p>Hello! I\'m EduBot, your intelligent tutor. How can I help you today?</p>';
        html += '</div>';
        $('#edubot-messages').html(html);
    }

    /**
     * Remove welcome message.
     */
    function removeWelcome() {
        $('.edubot-welcome').remove();
    }

    /**
     * Clear chat messages.
     */
    function clearChat() {
        $('#edubot-messages').empty();
    }

    /**
     * Update chat header.
     */
    function updateChatHeader(topic, courseName) {
        var text = topic || 'Conversation';
        if (courseName) {
            text += ' — ' + courseName;
        }
        $('#edubot-chat-topic').text(text);
    }

    /**
     * Scroll messages to bottom.
     */
    function scrollToBottom() {
        var $messages = $('#edubot-messages');
        $messages.scrollTop($messages[0].scrollHeight);
    }

    /**
     * Get course ID from page body classes.
     */
    function getPageCourseId() {
        var bodyClasses = $('body').attr('class') || '';
        var match = bodyClasses.match(/course-(\d+)/);
        return match ? parseInt(match[1]) : 0;
    }

    /**
     * Escape HTML to prevent XSS.
     */
    function escapeHtml(text) {
        if (!text) {
            return '';
        }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    return {
        init: init
    };
});
