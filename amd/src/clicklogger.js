// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for logging quiz interactions (answer selections during an attempt).
 * Captures real-time student interactions client-side, buffers them in sessionStorage,
 * and sends them to the server when the quiz is submitted.
 *
 * @module local_quizinteractions/clicklogger
 * @copyright 2026 Mohamed Hamza Falih <Mohamed_Hamza.Falih@univ-lemans.fr>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Session storage key for buffering interactions.
let sessionKey = '';
// Store attemptId and quizId for later use.
let attemptId = 0;
let quizId = 0;
let courseId = 0;
let saveRequestStarted = false;
let initialised = false;
let interactionBuffer = null;
let totalQuestions = 0;
const dropdownClickTimestamps = new Map();

/**
 * Get the current interactions buffer from sessionStorage.
 *
 * @returns {Array} Array of interaction objects.
 */
function getBufferedInteractions() {
    if (interactionBuffer === null) {
        const data = window.sessionStorage.getItem(sessionKey);
        interactionBuffer = data ? JSON.parse(data) : [];
    }
    return interactionBuffer;
}

/**
 * Save interactions buffer to sessionStorage.
 *
 * @param {Array} interactions Array of interaction objects.
 */
function setBufferedInteractions(interactions) {
    interactionBuffer = interactions;
    window.sessionStorage.setItem(sessionKey, JSON.stringify(interactions));
}

/**
 * Add a single interaction to the buffer.
 *
 * @param {Object} interaction Interaction object.
 */
function recordInteraction(interaction) {
    const interactions = getBufferedInteractions();
    interactions.push(interaction);
    setBufferedInteractions(interactions);
    console.log('[local_quizinteractions] interaction recorded', interaction);
}

/**
 * Add the common event metadata and synchronously buffer a non-answer event.
 *
 * @param {string} eventtype The interaction event type.
 * @param {Object} fields Event-specific fields.
 */
function recordEvent(eventtype, fields) {
    const timestamp = Date.now();
    recordInteraction({
        attemptid: attemptId,
        eventtype: eventtype,
        ...fields,
        timestamp: timestamp,
        datetime: new Date(timestamp).toISOString(),
    });
}

/**
 * Extract question metadata from a wide range of Moodle question markup.
 * Moodle 4.3 renders different question types with slightly different IDs and names,
 * so we check several likely sources instead of depending on one exact pattern.
 *
 * @param {HTMLElement} element The element that triggered the interaction.
 * @returns {{slot: number|null, questionid: number|null}} A metadata object.
 */
function extractQuestionMetadata(element) {
    const questionNode = element.closest('.que') || element.closest('[id^="question-"]');
    let slot = null;
    let questionid = null;
    const elementName = element.getAttribute('name') || '';
    const elementId = element.getAttribute('id') || '';

    if (questionNode) {
        const questionNodeId = questionNode.getAttribute('id') || '';
        const slotMatch = questionNodeId.match(/^question-(?:\d+-)?(\d+)$/i);
        if (slotMatch) {
            slot = parseInt(slotMatch[1], 10);
        }

        // In Moodle 4.3 this hidden value contains qid=<question bank id>.
        // The number before the slot in question-<usage>-<slot> is not the question id.
        const postDataNode = questionNode.querySelector('.questionflagpostdata');
        const postData = postDataNode ? (postDataNode.value || postDataNode.getAttribute('value') || '') : '';
        const qidMatch = postData.match(/(?:^|&)qid=(\d+)(?:&|$)/i);
        if (qidMatch) {
            questionid = parseInt(qidMatch[1], 10);
        }

        const dataQuestionId = questionNode.getAttribute('data-questionid')
            || questionNode.getAttribute('data-question-id');
        if (!questionid && dataQuestionId && /^\d+$/.test(dataQuestionId)) {
            questionid = parseInt(dataQuestionId, 10);
        }
    }

    const slotMatch = elementName.match(/q\d+:(\d+)(?=[:_]|$)/i)
        || elementName.match(/q\d+_(\d+)(?=[:_]|$)/i)
        || elementId.match(/q\d+:(\d+)(?=[:_]|$)/i);
    if (!slot && slotMatch) {
        slot = parseInt(slotMatch[1], 10);
    }

    return {
        slot: slot,
        questionid: questionid,
    };
}

/**
 * Determine the slot number for a given question element.
 * We use multiple patterns because Moodle renders different question types with
 * different names and IDs.
 *
 * @param {HTMLElement} element The element that triggered the interaction.
 * @returns {number|null} The slot number, or null if not found.
 */
function getSlotFromElement(element) {
    return extractQuestionMetadata(element).slot;
}

/**
 * Get the question ID from the element's data attributes or nearby elements.
 * Moodle stores this in several different forms depending on the question type.
 *
 * @param {HTMLElement} element The element that triggered the interaction.
 * @returns {number|null} The question ID, or null if not found.
 */
function getQuestionIdFromElement(element) {
    return extractQuestionMetadata(element).questionid;
}

/**
 * Get the answer ID or value from the input element.
 *
 * @param {HTMLInputElement|HTMLSelectElement} element The input element.
 * @returns {string} The answer ID or value.
 */
function getAnswerId(element) {
    if (element.type === 'radio' || element.type === 'checkbox') {
        // For radio/checkbox, use the value attribute.
        return element.value || element.id || '';
    } else if (element.tagName === 'SELECT') {
        // For select dropdowns, use the selected value.
        return element.value || '';
    }
    return '';
}

/**
 * Get the position index of an answer option within its question container.
 * Position reflects the order as it appears in the DOM (already shuffled/arranged).
 *
 * @param {HTMLElement} element The input element.
 * @returns {number} The 0-based position index.
 */
function getAnswerPosition(element) {
    if (element.type === 'radio' || element.type === 'checkbox') {
        const questionContainer = element.closest('.que');
        if (!questionContainer) {
            return 0;
        }

        const siblings = Array.from(
            questionContainer.querySelectorAll(`input[type="${element.type}"][name="${element.name}"]`)
        );
        return siblings.indexOf(element);
    }

    if (element.tagName === 'SELECT') {
        return element.selectedIndex;
    }

    return 0;
}

/**
 * Get the action type (checked, unchecked, or selected) based on the element's current state.
 *
 * @param {HTMLElement} element The element that triggered the event.
 * @returns {string} 'checked', 'unchecked', or 'selected'.
 */
function getAction(element) {
    if (element.type === 'radio' || element.type === 'checkbox') {
        return element.checked ? 'checked' : 'unchecked';
    } else if (element.tagName === 'SELECT') {
        return 'selected';
    }
    return 'unknown';
}

/**
 * Handle change events on input elements and select dropdowns.
 * Captures the interaction and buffers it.
 *
 * @param {Event} event The change event.
 */
function handleInteractionChange(event) {
    const element = event.target;

    // Only process input and select elements.
    if (!element.matches('input[type="radio"], input[type="checkbox"], select')) {
        return;
    }

    const slot = getSlotFromElement(element);
    const questionId = getQuestionIdFromElement(element);

    if (!slot || !questionId) {
        // Unable to determine slot or question ID; skip this interaction.
        return;
    }

    const timestamp = Date.now();
    const interaction = {
        attemptid: attemptId,
        eventtype: 'answer',
        elementname: element.getAttribute('name') || '',
        slot: slot,
        questionid: questionId,
        answerid: getAnswerId(element),
        position: getAnswerPosition(element),
        action: getAction(element),
        timestamp: timestamp,
        datetime: new Date(timestamp).toISOString(),
    };

    if (element.tagName === 'SELECT') {
        const elementName = element.getAttribute('name') || '';
        if (dropdownClickTimestamps.has(elementName)) {
            interaction.dropdownclicktimestamp = dropdownClickTimestamps.get(elementName);
            dropdownClickTimestamps.delete(elementName);
        }
    }

    recordInteraction(interaction);
}

/**
 * Get metadata for the first question shown on the current page.
 *
 * @returns {{slot: number|null, questionid: number|null}}
 */
function getCurrentQuestionMetadata() {
    const question = document.querySelector('.que');
    return question ? extractQuestionMetadata(question) : {slot: null, questionid: null};
}

/**
 * Record a click event using the question currently shown on this page.
 *
 * @param {string} eventtype The interaction event type.
 * @param {Object} fields Additional event-specific fields.
 * @param {HTMLElement|null} questionElement Question control for question-local events.
 */
function recordPageEvent(eventtype, fields, questionElement = null) {
    const metadata = questionElement
        ? extractQuestionMetadata(questionElement)
        : getCurrentQuestionMetadata();
    if (!metadata.slot) {
        return;
    }

    const eventFields = {
        slot: metadata.slot,
        ...fields,
    };
    if ((eventtype === 'verify' || eventtype === 'tryagain') && metadata.questionid) {
        eventFields.questionid = metadata.questionid;
    }
    recordEvent(eventtype, eventFields);
}

/**
 * Send buffered interactions to the server and clear the buffer.
 * This is called just before the quiz submission completes.
 *
 * @returns {Promise} A promise that resolves when the web service call completes.
 */
function sendInteractionsToServer() {
    const interactions = getBufferedInteractions();

    console.log('[local_quizinteractions] about to call web service', {
        attemptid: attemptId,
        quizid: quizId,
        count: interactions.length,
    });

    if (interactions.length === 0) {
        console.log('[local_quizinteractions] no interactions to send');
        return Promise.resolve();
    }

    const finishForm = document.getElementById('frm-finishattempt');
    const sesskeyElement = finishForm ? finishForm.querySelector('input[name="sesskey"]') : null;
    const sesskey = sesskeyElement ? sesskeyElement.value : '';
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(sesskey)}`;
    const body = [{
        index: 0,
        methodname: 'local_quizinteractions_log_interactions',
        args: {
            attemptid: attemptId,
            quizid: quizId,
            courseid: courseId,
            interactions: interactions,
        },
    }];

    // Use fetch keepalive rather than blocking Moodle's native form submission.
    // Native form.submit() bypasses submit events, while keepalive lets this request
    // continue when the confirmation flow navigates away immediately afterward.
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
        keepalive: true,
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then((results) => {
            const result = Array.isArray(results) ? results[0] : results;
            if (result && result.error) {
                throw new Error(result.exception || result.message || 'Moodle AJAX error');
            }
            return results;
        })
        .then(() => {
            console.log('[local_quizinteractions] web service call resolved');
            // Clear the buffer after successful submission.
            window.sessionStorage.removeItem(sessionKey);
            return true;
        })
        .catch((error) => {
            console.error('[local_quizinteractions] web service call rejected', error);
            throw error;
        });
}

/**
 * Capture student navigation and question-control clicks before Moodle handles them.
 *
 * @param {MouseEvent} event The click event.
 */
function handleDocumentClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
        return;
    }

    const verifyButton = target.closest('button[name$="_-submit"], input[name$="_-submit"]');
    const tryAgainButton = target.closest('button[name$="_-tryagain"], input[name$="_-tryagain"]');
    const previousButton = target.closest('#responseform [name="previous"]');
    const nextButton = target.closest('#responseform [name="next"]');
    const navigationButton = target.closest('[id^="quiznavbutton"]');
    const returnButton = target.closest('.tertiary-navigation .navitem a.btn.btn-secondary');

    if (verifyButton) {
        recordPageEvent('verify', {}, verifyButton);
    } else if (tryAgainButton) {
        recordPageEvent('tryagain', {}, tryAgainButton);
    } else if (previousButton) {
        recordPageEvent('previouspage', {totalquestions: totalQuestions});
    } else if (nextButton) {
        const nextPage = document.querySelector('#responseform input[name="nextpage"]');
        const isLastPage = nextPage && nextPage.value === '-1';
        recordPageEvent(isLastPage ? 'finishtest' : 'nextpage', {totalquestions: totalQuestions});
    } else if (navigationButton) {
        const slotMatch = navigationButton.id.match(/^quiznavbutton(\d+)$/);
        if (slotMatch) {
            recordPageEvent('navigate', {
                targetslot: parseInt(slotMatch[1], 10),
                totalquestions: totalQuestions,
            });
        }
    } else if (returnButton) {
        recordPageEvent('return', {totalquestions: totalQuestions});
    }

    const finishButton = target.closest('#frm-finishattempt button[type="submit"], '
        + '#frm-finishattempt input[type="submit"]');
    if (!finishButton) {
        return;
    }

    console.log('[local_quizinteractions] final-submit click detected', finishButton);
    if (saveRequestStarted) {
        console.log('[local_quizinteractions] save request already started');
        return;
    }

    saveRequestStarted = true;
    sendInteractionsToServer().catch(() => {
        // The browser continues Moodle's own submission regardless of logging failure.
    });
}

/**
 * Initialize the quiz interaction logger.
 * Should be called once per quiz attempt page load.
 *
 * @param {number} atid The quiz attempt ID.
 * @param {number} qid The quiz module instance ID.
 * @param {number} cid The course ID.
 * @param {number} totalquestioncount Number of real questions in this quiz.
 */
export const init = function (atid, qid, cid, totalquestioncount) {
    console.log('[local_quizinteractions] init called', {
        attemptid: atid,
        quizid: qid,
        courseid: cid,
        pagetype: document.body.dataset.pagetype || 'unknown',
    });

    if (initialised) {
        console.log('[local_quizinteractions] init skipped because already initialized');
        return;
    }

    initialised = true;
    attemptId = atid;
    quizId = qid;
    courseId = cid;
    totalQuestions = totalquestioncount;
    sessionKey = 'local_quizinteractions_' + attemptId;

    // Set up event delegation for answer option changes.
    // Use event delegation on the document (or a quiz container) to catch all interactions.
    const quizContainer = document.querySelector('[data-region="quiz-attempt"]') || document.body;
    quizContainer.addEventListener('change', handleInteractionChange, true); // true = capture phase
    console.log('[local_quizinteractions] change handler attached', {
        container: quizContainer === document.body ? 'document.body' : '[data-region="quiz-attempt"]',
    });

    document.addEventListener('mousedown', (event) => {
        const element = event.target instanceof Element ? event.target : null;
        if (!element || !element.matches('select')) {
            return;
        }
        const elementName = element.getAttribute('name') || '';
        if (elementName) {
            dropdownClickTimestamps.set(elementName, Date.now());
        }
    }, true);
    document.addEventListener('click', handleDocumentClick, true);
};
